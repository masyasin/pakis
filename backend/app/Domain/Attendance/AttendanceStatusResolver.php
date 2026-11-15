<?php

namespace App\Domain\Attendance;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Support\BasicDayStatus;
use App\Domain\Attendance\Support\DaySnapshot;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Status harian lengkap (BR-068, 03 §4.6, T-037). Idempoten per (pegawai, tanggal).
 *
 * Tiga pintu:
 * - `resolve($day)` — titik pakai DayStatusResolver: RecordAttendance (T-036) dan koreksi (T-040)
 *   memanggilnya di transaksi mereka SEBELUM menyimpan.
 * - `resolveDate($date)` — job `attendance:resolve-day` (tiap 15 menit, keputusan Mas 15 Nov 2026):
 *   membuat baris `holiday`/`off`/`absent` bagi pegawai yang tidak absen dan memperbarui yang ada.
 * - `recompute($employeeId, $from, $to)` — hitung ulang baris yang SUDAH ada setelah mutasi
 *   (BR-052) atau perubahan jadwal/libur lampau (OQ-021). Tidak pernah membuat baris tanggal lama:
 *   tanpa ini mutasi bertanggal mundur akan memunculkan "Tidak hadir" sebelum sistem dipakai.
 *
 * Resolver TIDAK pernah mengubah jam masuk/pulang, log, atau `has_anomaly`: jam hanya diisi
 * RecordAttendance atau koreksi, sehingga baris terkoreksi aman tanpa cabang khusus.
 */
class AttendanceStatusResolver extends BasicDayStatus
{
    private const CHUNK = 500;

    /** @var array<string, EffectiveSchedule> "pegawai|tanggal" → jadwal yang sudah dimuat per batch */
    private array $schedules = [];

    public function __construct(private readonly ScheduleResolver $scheduleResolver) {}

    public function resolve(AttendanceDay $day): void
    {
        $original = $day->getOriginal('resolved_at');

        parent::resolve($day);

        // `resolved_at` hanya maju bila ada yang berubah: job yang diulang menghasilkan baris identik.
        if ($day->exists && array_keys($day->getDirty()) === ['resolved_at']) {
            $day->resolved_at = $original;
        }
    }

    /**
     * Aturan BR-068 langkah (1)–(4) di depan aturan dasar (telat → pulang cepat → hadir).
     *
     * @return list<Closure(AttendanceDay): ?AttendanceStatus>
     */
    protected function rules(): array
    {
        return [
            fn (AttendanceDay $d) => $this->scheduleOf($d)->kind === EffectiveSchedule::HOLIDAY ? AttendanceStatus::Holiday : null,
            fn (AttendanceDay $d) => ! $this->scheduleOf($d)->isWorkday() && $d->check_in_at === null && $d->check_out_at === null ? AttendanceStatus::Off : null,
            fn (AttendanceDay $d) => $d->check_in_at === null && $this->dayClosed($d) ? AttendanceStatus::Absent : null,
            fn (AttendanceDay $d) => $d->check_in_at !== null && $d->check_out_at === null && $this->checkoutClosed($d) ? AttendanceStatus::NoCheckout : null,
            ...parent::rules(),
        ];
    }

    /**
     * Semua pegawai yang bekerja pada tanggal itu (atau `$employeeIds`).
     *
     * @param  list<int>|null  $employeeIds
     * @return array{created: int, updated: int, deleted: int}
     */
    public function resolveDate(string $date, ?array $employeeIds = null): array
    {
        $total = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        Employee::query()
            ->where('join_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('exit_date')->orWhere('exit_date', '>=', $date))
            ->when($employeeIds !== null, fn (Builder $q) => $q->whereIn('id', $employeeIds ?? []))
            ->select('id')
            ->chunkById(self::CHUNK, function ($chunk) use ($date, &$total) {
                $ids = array_values(array_map(intval(...), $chunk->pluck('id')->all()));
                $total = self::add($total, $this->resolveDays($ids, $date, $date, create: true));
            });

        return $total;
    }

    /**
     * Hitung ulang baris yang sudah ada di [from, min(to, hari ini)].
     *
     * @param  list<int>|null  $employeeIds  null = semua pegawai yang punya baris di rentang itu
     * @return array{created: int, updated: int, deleted: int}
     */
    public function recompute(?array $employeeIds, string $from, ?string $to = null): array
    {
        $today = DisplayTime::toLocal(now())->toDateString();
        $to = $to === null ? $today : min($to, $today);
        $total = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        if ($from > $to) {
            return $total;
        }

        $ids = $employeeIds ?? AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->distinct()
            ->pluck('employee_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $total = self::add($total, $this->resolveDays($chunk, $from, $to, create: false));
        }

        return $total;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{created: int, updated: int, deleted: int}
     */
    private function resolveDays(array $employeeIds, string $from, string $to, bool $create): array
    {
        $result = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        if ($employeeIds === []) {
            return $result;
        }

        $schedules = $this->scheduleResolver->forRange($employeeIds, $from, $to);
        $assignments = self::primaryAssignments($employeeIds, $from, $to);
        $employees = Employee::withTrashed()->whereIn('id', $employeeIds)->get(['id', 'join_date', 'exit_date', 'deleted_at'])->keyBy('id');
        $existing = AttendanceDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$from, $to])
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($days) => $days->keyBy(fn (AttendanceDay $d) => $d->work_date->toDateString()));

        foreach ($employeeIds as $employeeId) {
            foreach ($schedules[$employeeId] ?? [] as $date => $schedule) {
                $day = $existing->get($employeeId)?->get($date);
                if ($day === null && ! $create) {
                    continue;
                }

                $employee = $employees->get($employeeId);
                $employed = $employee !== null && $employee->deleted_at === null
                    && $employee->join_date->toDateString() <= $date
                    && ($employee->exit_date === null || $employee->exit_date->toDateString() >= $date);

                $outcome = $this->resolveOne($employeeId, $date, $schedule, self::assignmentOn($assignments[$employeeId] ?? [], $date), $employed, $day !== null);
                if ($outcome !== null) {
                    $result[$outcome]++;
                }
            }
        }

        return $result;
    }

    /** @return 'created'|'updated'|'deleted'|null */
    private function resolveOne(int $employeeId, string $date, EffectiveSchedule $schedule, ?Assignment $assignment, bool $employed, bool $exists, bool $retry = true): ?string
    {
        try {
            return DB::transaction(function () use ($employeeId, $date, $schedule, $assignment, $employed) {
                // Dikunci: absen yang masuk bersamaan (RecordAttendance) menunggu hitungan ini selesai.
                $day = AttendanceDay::query()->where('employee_id', $employeeId)->where('work_date', $date)->lockForUpdate()->first();

                if (! $employed) {
                    // Pegawai belum masuk/sudah keluar pada tanggal itu: baris turunan (tanpa absen) dibuang.
                    if ($day !== null && $day->check_in_log_id === null && $day->check_out_log_id === null) {
                        $day->delete();

                        return 'deleted';
                    }
                    if ($day === null) {
                        return null;
                    }
                }

                $isNew = $day === null;
                $day ??= new AttendanceDay(['employee_id' => $employeeId, 'work_date' => $date]);

                $this->snapshot($day, $date, $schedule, $assignment);
                $this->schedules[$employeeId.'|'.$date] = $schedule;
                try {
                    $this->resolve($day);
                } finally {
                    unset($this->schedules[$employeeId.'|'.$date]);
                }

                // Baris baru hanya bila statusnya sudah pasti; hari kerja yang belum ditutup menunggu absen.
                if ($isNew && ! in_array($day->status, [AttendanceStatus::Holiday, AttendanceStatus::Off, AttendanceStatus::Absent], true)) {
                    return null;
                }
                if (! $day->isDirty()) {
                    return null;
                }

                $day->save();

                return $isNew ? 'created' : 'updated';
            });
        } catch (UniqueConstraintViolationException $e) {
            // Absen pertama pegawai tersimpan tepat di antara baca & tulis: ulangi sekali dengan baris itu.
            if (! $retry) {
                throw $e;
            }

            return $this->resolveOne($employeeId, $date, $schedule, $assignment, $employed, true, false);
        }
    }

    /**
     * Snapshot jadwal & organisasi pada tanggal itu (BR-068), sama dengan yang diisi RecordAttendance.
     */
    private function snapshot(AttendanceDay $day, string $date, EffectiveSchedule $schedule, ?Assignment $assignment): void
    {
        DaySnapshot::apply($day, $date, $schedule, $assignment);
    }

    private function scheduleOf(AttendanceDay $day): EffectiveSchedule
    {
        $date = $day->work_date->toDateString();

        return $this->schedules[$day->employee_id.'|'.$date]
            ??= $this->scheduleResolver->forDate($day->employee_id, $date);
    }

    /**
     * BR-068 (3): hari ditutup = jam `attendance.daily_close_time` lokal, tetapi tidak sebelum shift
     * selesai; shift lintas tengah malam ditutup setelah jendela pulangnya lewat.
     */
    private function dayClosed(AttendanceDay $day): bool
    {
        $date = $day->work_date->toDateString();
        $close = CarbonImmutable::parse($date.' '.setting('attendance.daily_close_time'), DisplayTime::timezone())->utc();

        $end = $day->scheduled_end;
        if ($end !== null) {
            $shift = $day->shift;
            if ($shift !== null && $shift->crosses_midnight) {
                $end = $end->addMinutes($shift->checkout_close_min);
            }
            if ($end->greaterThan($close)) {
                $close = $end;
            }
        }

        return CarbonImmutable::now()->greaterThanOrEqualTo($close);
    }

    /** BR-068 (4): jendela pulang (BR-060) lewat; tanpa shift = hari ditutup. */
    private function checkoutClosed(AttendanceDay $day): bool
    {
        $shift = $day->shift;
        if ($shift === null || $day->scheduled_end === null) {
            return $this->dayClosed($day);
        }

        return CarbonImmutable::now()->greaterThan($day->scheduled_end->addMinutes($shift->checkout_close_min));
    }

    /**
     * Penugasan utama yang beririsan dengan rentang, dengan departemen jabatannya.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<Assignment>> urut start_date naik
     */
    private static function primaryAssignments(array $employeeIds, string $from, string $to): array
    {
        $grouped = [];
        Assignment::query()
            ->with('position:id,department_id')
            ->whereIn('employee_id', $employeeIds)
            ->where('is_primary', true)
            ->where('start_date', '<=', $to)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->each(function (Assignment $a) use (&$grouped) {
                $grouped[$a->employee_id][] = $a;
            });

        return $grouped;
    }

    /** @param  list<Assignment>  $assignments  urut start_date naik */
    private static function assignmentOn(array $assignments, string $date): ?Assignment
    {
        $found = null;
        foreach ($assignments as $a) {
            if ($a->start_date->toDateString() <= $date && ($a->end_date === null || $a->end_date->toDateString() >= $date)) {
                $found = $a;
            }
        }

        return $found;
    }

    /**
     * @param  array{created: int, updated: int, deleted: int}  $a
     * @param  array{created: int, updated: int, deleted: int}  $b
     * @return array{created: int, updated: int, deleted: int}
     */
    private static function add(array $a, array $b): array
    {
        return ['created' => $a['created'] + $b['created'], 'updated' => $a['updated'] + $b['updated'], 'deleted' => $a['deleted'] + $b['deleted']];
    }
}
