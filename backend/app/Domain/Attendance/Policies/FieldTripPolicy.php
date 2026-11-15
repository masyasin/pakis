<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Models\User;
use App\Support\Time\DisplayTime;

/**
 * Tugas luar kota (06 §7.2a), didaftarkan di AttendanceServiceProvider. Melihat = binding
 * ber-scope (salah satu pegawai dalam scope). Mengubah/membatalkan
 * butuh SEMUA pegawai tugas dalam scope pelaku, agar atasan tidak mengubah tugas yang juga
 * mengikat pegawai lokasi/tim lain.
 */
class FieldTripPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function update(User $user, FieldTrip $trip): bool
    {
        return $trip->status === FieldTripStatus::Approved
            && $trip->end_date->toDateString() >= DisplayTime::toLocal(now())->toDateString()
            && $this->manages($user, $trip);
    }

    public function cancel(User $user, FieldTrip $trip): bool
    {
        return $trip->status === FieldTripStatus::Approved && $this->manages($user, $trip);
    }

    private function manages(User $user, FieldTrip $trip): bool
    {
        if (! $user->can('field_trips.manage')) {
            return false;
        }

        foreach ($trip->employeeIds() as $employeeId) {
            if (! $this->scope->allows($user, $employeeId)) {
                return false;
            }
        }

        return true;
    }
}
