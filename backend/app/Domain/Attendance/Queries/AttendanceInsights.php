<?php

namespace App\Domain\Attendance\Queries;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Support\AttendanceDayScope;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Data grafik dasbor (07 §4 "Dasbor", keputusan Mas 16 Nov 2026) untuk satu rentang tanggal kerja:
 * tren kehadiran per hari, kehadiran per lokasi, dan sebaran jam masuk. Melengkapi TodaySummary
 * (kartu angka hari ini) tanpa mengubahnya.
 *
 * Aturan yang sama dengan TodaySummary dan rekap bulanan:
 * - selalu lewat AttendanceDayScope (scope lokasi = snapshot `attendance_days.location_id`);
 * - "hadir" = ada presensi masuk (BR-072), "telat" = hadir dengan `late_minutes > 0`,
 *   "tepat waktu" = hadir tanpa telat, "tidak hadir" = status `absent` (baris dibuat resolver T-037
 *   setelah hari ditutup, jadi hari ini baru terisi setelah jam tutup);
 * - pegawai terhapus (BR-055) tidak ikut dihitung.
 */
final class AttendanceInsights
{
    /** Rentang bawaan bila URL tidak membawa tanggal (hari, termasuk hari ini). */
    public const DEFAULT_DAYS = 7;

    /** Lebar kelompok jam masuk pada histogram. */
    private const BUCKET_MINUTES = 15;

    /**
     * Batas pemangkasan pencilan sebaran jam masuk (% presensi per sisi). Ini aturan tampilan
     * grafik, bukan parameter bisnis, sehingga tidak masuk registri setting.
     */
    private const OUTLIER_PERCENT = 2;

    public function __construct(private readonly AttendanceDayScope $days) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     max_days: int,
     *     trend: list<array{date: string, on_time: int, late: int, absent: int}>,
     *     locations: list<array{name: string, present: int, late: int}>,
     *     check_ins: list<array{time: string, count: int}>,
     *     check_ins_outside: int
     * }
     */
    public function for(User $user, ?string $from = null, ?string $to = null): array
    {
        [$start, $end, $max] = self::range($from, $to);
        $checkIns = $this->checkIns($user, $start, $end);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'max_days' => $max,
            'trend' => $this->trend($user, $start, $end),
            'locations' => $this->locations($user, $start, $end),
            'check_ins' => $checkIns['series'],
            'check_ins_outside' => $checkIns['outside'],
        ];
    }

    /**
     * Rentang dari URL, dirapikan (bukan ditolak): tanggal rusak → bawaan 7 hari terakhir; tanggal
     * depan dipotong ke hari ini; dari > sampai ditukar; lebih panjang dari
     * `ui.dashboard_max_range_days` dipotong dari belakang (tanggal "sampai" dipertahankan).
     *
     * @return array{CarbonImmutable, CarbonImmutable, int}
     */
    public static function range(?string $from, ?string $to): array
    {
        $today = CarbonImmutable::parse(DisplayTime::toLocal(now())->toDateString());
        $max = (int) setting('ui.dashboard_max_range_days');

        $end = self::date($to) ?? $today;
        $start = self::date($from) ?? $end->subDays(self::DEFAULT_DAYS - 1);
        if ($end->gt($today)) {
            $end = $today;
        }
        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) + 1 > $max) {
            $start = $end->subDays($max - 1);
        }

        return [$start, $end, $max];
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }

        // createFromFormat menggeser tanggal mustahil (31 Feb → 3 Mar); tolak alih-alih menebak.
        return $date !== null && $date->toDateString() === $value ? $date : null;
    }

    /** @return list<array{date: string, on_time: int, late: int, absent: int}> */
    private function trend(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->scoped($user, $start, $end)
            ->groupBy('work_date')
            ->selectRaw(
                'work_date,'
                .' SUM(CASE WHEN check_in_at IS NOT NULL AND late_minutes = 0 THEN 1 ELSE 0 END) AS on_time,'
                .' SUM(CASE WHEN check_in_at IS NOT NULL AND late_minutes > 0 THEN 1 ELSE 0 END) AS late,'
                .' SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS absent',
                [AttendanceStatus::Absent->value],
            )
            ->toBase()
            ->get()
            ->keyBy(fn (object $r) => substr((string) $r->work_date, 0, 10));

        $trend = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $row = $rows->get($d->toDateString());
            $trend[] = [
                'date' => $d->toDateString(),
                'on_time' => (int) ($row->on_time ?? 0),
                'late' => (int) ($row->late ?? 0),
                'absent' => (int) ($row->absent ?? 0),
            ];
        }

        return $trend;
    }

    /**
     * Jumlah hari-hadir & hari-telat per lokasi dalam rentang (snapshot penugasan saat presensi),
     * urut terbanyak. Kosong bila hanya satu lokasi: grafiknya tidak memberi informasi baru.
     *
     * @return list<array{name: string, present: int, late: int}>
     */
    private function locations(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->scoped($user, $start, $end)
            ->whereNotNull('check_in_at')
            ->whereNotNull('location_id')
            ->groupBy('location_id')
            ->selectRaw('location_id, COUNT(*) AS present, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) AS late')
            ->toBase()
            ->get();

        if ($rows->count() < 2) {
            return [];
        }

        $names = Location::query()->whereIn('id', $rows->pluck('location_id'))->pluck('name', 'id');

        return array_values($rows
            ->map(fn (object $r) => [
                'name' => (string) ($names[(int) $r->location_id] ?? '–'),
                'present' => (int) $r->present,
                'late' => (int) $r->late,
            ])
            ->sortByDesc('present')
            ->all());
    }

    /**
     * Jumlah presensi masuk dalam rentang per 15 menit jam lokal (`general.timezone`), dari
     * kelompok paling awal sampai paling akhir yang terisi; kelompok kosong di tengah tetap ditulis 0
     * agar sumbu waktunya rata. Dikelompokkan di DB agar rentang panjang tidak menarik semua baris.
     * Geser UTC→lokal memakai selisih tetap: zona yang diizinkan (WIB/WITA/WIT) tidak punya DST.
     *
     * @return array{series: list<array{time: string, count: int}>, outside: int}
     */
    private function checkIns(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $offset = CarbonImmutable::now(DisplayTime::timezone())->utcOffset();

        $buckets = $this->scoped($user, $start, $end)
            ->whereNotNull('check_in_at')
            ->groupBy('bucket')
            ->selectRaw(
                'FLOOR(MOD(HOUR(check_in_at) * 60 + MINUTE(check_in_at) + ? + 1440, 1440) / ?) AS bucket, COUNT(*) AS total',
                [$offset, self::BUCKET_MINUTES],
            )
            ->toBase()
            ->pluck('total', 'bucket')
            ->mapWithKeys(fn (mixed $total, mixed $bucket) => [(int) $bucket * self::BUCKET_MINUTES => (int) $total])
            ->all();

        if ($buckets === []) {
            return ['series' => [], 'outside' => 0];
        }

        // Pencilan (mis. satu presensi tengah malam) memanjangkan sumbu sampai batang pagi tak
        // terbaca: pangkas kelompok di kedua ujung selama totalnya ≤ 2% presensi. Jumlah yang
        // dipangkas dikirim agar layar bisa menyebutkannya (tidak hilang diam-diam).
        ksort($buckets);
        $budget = intdiv(array_sum($buckets) * self::OUTLIER_PERCENT, 100);
        $outside = 0;
        foreach ([false, true] as $fromEnd) {
            while (count($buckets) > 1) {
                $edge = $fromEnd ? array_key_last($buckets) : array_key_first($buckets);
                if ($outside + $buckets[$edge] > $budget) {
                    break;
                }
                $outside += $buckets[$edge];
                unset($buckets[$edge]);
            }
        }

        // Kunci sudah urut (ksort) dan minimal satu kelompok tersisa (pemangkasan berhenti di 1).
        $first = array_key_first($buckets);
        $last = array_key_last($buckets);
        $series = [];
        for ($m = $first; $m <= $last; $m += self::BUCKET_MINUTES) {
            $series[] = [
                'time' => sprintf('%02d:%02d', intdiv($m, 60), $m % 60),
                'count' => $buckets[$m] ?? 0,
            ];
        }

        return ['series' => $series, 'outside' => $outside];
    }

    /** @return Builder<AttendanceDay> */
    private function scoped(User $user, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->days
            ->apply(AttendanceDay::query()->whereHas('employee'), $user)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()]);
    }
}
