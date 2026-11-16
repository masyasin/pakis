<?php

namespace App\Domain\Attendance\Queries;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\EffectiveSchedule;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\ScheduleResolver;
use App\Domain\Attendance\Support\AttendanceDayScope;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Angka kehadiran dasbor (07 §4 "Dasbor", FR-040, T-044), selalu dalam scope pengguna:
 * - hadir & telat dari `attendance_days` hari ini (AttendanceDayScope: scope lokasi = snapshot);
 * - libur/off & belum presensi dari jadwal efektif hari ini (ScheduleResolver) pegawai aktif dalam
 *   DataScope, karena baris hari kerja yang belum ditutup & belum presensi memang belum ada (T-037);
 * - 7 hari terakhir: jumlah hadir & telat per tanggal.
 * Definisi "hadir" = ada presensi masuk, sama dengan BR-072.
 */
final class TodaySummary
{
    /** Batch jadwal: ScheduleResolver memuat pola/override per kumpulan pegawai. */
    private const BATCH = 500;

    public const DAYS = 7;

    public function __construct(
        private readonly AttendanceDayScope $days,
        private readonly DataScope $employees,
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * @return array{date: string, present: int, late: int, not_yet: int, off: int, week: list<array{date: string, present: int, late: int}>}
     */
    public function for(User $user): array
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        $present = $this->scopedDays($user)->where('work_date', $today)->whereNotNull('check_in_at');
        $late = (clone $present)->where('late_minutes', '>', 0)->count();
        $checkedIn = array_flip(array_map('intval', $present->pluck('employee_id')->all()));

        [$off, $notYet] = $this->scheduleCounts($user, $today, $checkedIn);

        return [
            'date' => $today,
            'present' => count($checkedIn),
            'late' => $late,
            'not_yet' => $notYet,
            'off' => $off,
            'week' => $this->week($user, $today),
        ];
    }

    /**
     * Libur/off (hari libur, libur pola/override) dan hari kerja tanpa presensi masuk.
     * Pegawai "tidak dijadwalkan" tidak dihitung keduanya (muncul di "Pegawai tanpa jadwal").
     *
     * @param  array<int, int>  $checkedIn
     * @return array{0: int, 1: int}
     */
    private function scheduleCounts(User $user, string $today, array $checkedIn): array
    {
        $query = Employee::query()->where('employees.status', EmployeeStatus::Active->value)
            ->where(fn (Builder $q) => $q->whereNull('employees.join_date')->orWhere('employees.join_date', '<=', $today));
        $off = 0;
        $notYet = 0;

        $this->employees->apply($query, $user, 'employees.id')
            ->select('employees.id')
            ->chunkById(self::BATCH, function ($chunk) use ($today, $checkedIn, &$off, &$notYet) {
                $ids = array_values(array_map('intval', $chunk->pluck('id')->all()));
                foreach ($this->schedules->forRange($ids, $today, $today) as $employeeId => $byDate) {
                    $schedule = $byDate[$today];
                    if (in_array($schedule->kind, [EffectiveSchedule::HOLIDAY, EffectiveSchedule::OFF], true)) {
                        $off++;
                    } elseif ($schedule->isWorkday() && ! isset($checkedIn[$employeeId])) {
                        $notYet++;
                    }
                }
            }, 'employees.id', 'id');

        return [$off, $notYet];
    }

    /** @return list<array{date: string, present: int, late: int}> */
    private function week(User $user, string $today): array
    {
        $to = CarbonImmutable::parse($today);
        $from = $to->subDays(self::DAYS - 1);

        $rows = $this->scopedDays($user)
            ->whereBetween('work_date', [$from->toDateString(), $today])
            ->groupBy('work_date')
            ->selectRaw('work_date, COUNT(check_in_at) AS present, SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) AS late')
            ->toBase()
            ->get()
            ->keyBy(fn (object $r) => substr((string) $r->work_date, 0, 10));

        $week = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $row = $rows->get($d->toDateString());
            $week[] = [
                'date' => $d->toDateString(),
                'present' => (int) ($row->present ?? 0),
                'late' => (int) ($row->late ?? 0),
            ];
        }

        return $week;
    }

    /** @return Builder<AttendanceDay> */
    private function scopedDays(User $user): Builder
    {
        // Pegawai terhapus (salah input, BR-055) tidak ikut dihitung, sama dengan daftar presensi.
        return $this->days->apply(AttendanceDay::query()->whereHas('employee'), $user);
    }
}
