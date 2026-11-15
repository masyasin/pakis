<?php

namespace App\Domain\Access\Support;

use App\Domain\Employee\Enums\EmployeeStatus as Status;
use App\Domain\Employee\Models\Employee;
use App\Models\User;

/**
 * Implementasi bawaan EmployeeStatus (BR-010). Akun tanpa pegawai selalu boleh.
 *
 * FAIL-CLOSED: akun yang menunjuk pegawai tetapi pegawainya tidak ditemukan (mis. sudah dihapus)
 * atau tidak `active` ditolak.
 */
final class ActiveEmployeeStatus implements EmployeeStatus
{
    public function isActive(User $user): bool
    {
        if ($user->employee_id === null) {
            return true;
        }

        return Employee::query()->whereKey($user->employee_id)->value('status') === Status::Active;
    }
}
