<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Time\DisplayTime;

/**
 * Isi tab Jadwal di detail pegawai (07 §4, T-035): pola yang berlaku hari ini, riwayat & pola
 * terjadwal, dan pengecualian mulai hari ini. Dipanggil EmployeeController hanya saat tab itu dibuka.
 */
final class EmployeeSchedule
{
    /** Jendela tampilan pengecualian mendatang (bukan parameter bisnis). */
    public const UPCOMING_DAYS = 30;

    /**
     * @return array{
     *     patterns: list<array{id: int, effective_from: string, status: string, days: array<string, array{id: int, code: string, name: string, color: string, active: bool}|null>}>,
     *     overrides: list<array{id: int, date: string, shift: array{id: int, code: string, name: string, color: string, active: bool}|null, note: string|null}>,
     *     upcomingDays: int,
     *     canManage: bool,
     * }
     */
    public static function for(Employee $employee, User $actor): array
    {
        $today = DisplayTime::toLocal(now())->toDateString();
        $until = DisplayTime::toLocal(now())->addDays(self::UPCOMING_DAYS)->toDateString();

        $patterns = ShiftPattern::query()->where('employee_id', $employee->id)->orderByDesc('effective_from')->get();
        $shifts = ShiftPattern::shiftsOf($patterns);
        // Pola berlaku = yang terbaru ≤ hari ini; yang lebih baru = terjadwal, yang lebih lama = riwayat.
        $currentId = $patterns->first(fn (ShiftPattern $p) => $p->effective_from->toDateString() <= $today)?->id;

        $overrides = ScheduleOverride::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('date', [$today, $until])
            ->with('shift:id,code,name,color,is_active,deleted_at')
            ->orderBy('date')
            ->get();

        return [
            'patterns' => array_values($patterns->map(fn (ShiftPattern $p) => [
                'id' => $p->id,
                'effective_from' => $p->effective_from->toDateString(),
                'status' => match (true) {
                    $p->id === $currentId => 'current',
                    $p->effective_from->toDateString() > $today => 'scheduled',
                    default => 'past',
                },
                'days' => array_map(fn (?int $id) => $id === null ? null : $shifts->get($id)?->summary(), $p->days()),
            ])->all()),
            'overrides' => array_values($overrides->map(fn (ScheduleOverride $o) => [
                'id' => $o->id,
                'date' => $o->date->toDateString(),
                'shift' => $o->shift?->summary(),
                'note' => $o->note,
            ])->all()),
            'upcomingDays' => self::UPCOMING_DAYS,
            'canManage' => $employee->isActive()
                && $actor->can('schedules.manage')
                && app(DataScope::class)->allows($actor, $employee->id),
        ];
    }
}
