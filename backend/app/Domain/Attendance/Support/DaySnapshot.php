<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\EffectiveSchedule;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Employee\Models\Assignment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Snapshot jadwal & organisasi satu baris `attendance_days` pada tanggalnya (BR-068). Satu pintu
 * untuk resolver harian (T-037) dan koreksi (T-040) agar baris yang dibuat keduanya identik;
 * RecordAttendance mengisi dari kandidat work_date-nya sendiri tetapi memakai penugasan yang sama.
 */
final class DaySnapshot
{
    public static function apply(AttendanceDay $day, string $date, EffectiveSchedule $schedule, ?Assignment $assignment): void
    {
        $shift = $schedule->isWorkday() ? $schedule->shift : null;
        [$start, $end] = $shift !== null ? ShiftTimes::on($date, $shift) : [null, null];

        $day->location_id = $assignment?->location_id;
        $day->position_id = $assignment?->position_id;
        $day->department_id = $assignment?->position?->department_id;
        $day->shift_id = $shift?->id;
        $day->scheduled_start = $start;
        $day->scheduled_end = $end;
        $day->setRelation('shift', $shift);
    }

    /** Penugasan utama yang berlaku pada tanggal itu (bukan hari ini: absen offline/tanggal lampau). */
    public static function primaryAssignmentOn(int $employeeId, string $date): ?Assignment
    {
        return Assignment::query()
            ->with(['location', 'position'])
            ->where('employee_id', $employeeId)
            ->where('is_primary', true)
            ->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->first();
    }
}
