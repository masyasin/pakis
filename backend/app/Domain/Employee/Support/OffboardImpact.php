<?php

namespace App\Domain\Employee\Support;

use App\Domain\Access\Actions\ListSessions;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Employee\Actions\CreateAssignment;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use App\Support\Time\DisplayTime;

/**
 * Ringkasan dampak sebelum menonaktifkan pegawai (07 §4 "Nonaktifkan pegawai", BR-053): apa saja
 * yang akan dicabut/ditutup, dan bawahan yang masih menunjuk pegawai ini sebagai atasan supaya HRD
 * bisa memindahkannya lebih dulu.
 */
final class OffboardImpact
{
    /**
     * @return array{
     *     account: array{username: string, active: bool}|null,
     *     sessions: int,
     *     tokens: int,
     *     devices_active: int,
     *     devices_pending: int,
     *     assignments: int,
     *     headed_locations: list<string>,
     *     subordinates: list<array{id: int, name: string, number: string}>
     * }
     */
    public static function for(Employee $employee): array
    {
        $user = User::query()->where('employee_id', $employee->id)->first();
        $today = DisplayTime::toLocal(now())->toDateString();

        $subordinateIds = Assignment::query()
            ->where('supervisor_employee_id', $employee->id)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->distinct()->pluck('employee_id');

        return [
            'account' => $user !== null ? ['username' => $user->username, 'active' => $user->is_active] : null,
            'sessions' => $user !== null ? ListSessions::query($user)->count() : 0,
            'tokens' => $user !== null ? $user->tokens()->count() : 0,
            'devices_active' => $user !== null ? Device::query()->where('user_id', $user->id)->where('status', DeviceStatus::Active)->count() : 0,
            'devices_pending' => $user !== null ? Device::query()->where('user_id', $user->id)->where('status', DeviceStatus::Pending)->count() : 0,
            'assignments' => CreateAssignment::stillRunning($employee->id)->count(),
            'headed_locations' => array_values(Location::query()->where('head_employee_id', $employee->id)->orderBy('name')->pluck('name')->all()),
            'subordinates' => array_values(Employee::query()->whereKey($subordinateIds)->where('status', 'active')->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'number' => $e->employee_number])
                ->all()),
        ];
    }
}
