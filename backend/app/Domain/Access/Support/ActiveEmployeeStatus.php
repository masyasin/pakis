<?php

namespace App\Domain\Access\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implementasi bawaan EmployeeStatus. Akun tanpa pegawai selalu boleh.
 *
 * FAIL-CLOSED: akun yang menunjuk pegawai tetapi status pegawainya tidak bisa dipastikan (tabel
 * belum ada, baris tidak ditemukan) ditolak. TODO(T-024): pakai model Employee + enum status.
 */
final class ActiveEmployeeStatus implements EmployeeStatus
{
    public function isActive(User $user): bool
    {
        if ($user->employee_id === null) {
            return true;
        }

        if (! Schema::hasTable('employees')) {
            return false;
        }

        return DB::table('employees')->where('id', $user->employee_id)->value('status') === 'active';
    }
}
