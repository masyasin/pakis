<?php

namespace App\Domain\Employee\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\EmployeeUsage;
use App\Models\User;

/**
 * Pegawai (06 §3–§5): permission + scope data. Route sudah dijaga `can:` dan binding ber-scope
 * (di luar scope → 404); Policy ini lapisan kedua sehingga setiap aksi atas pegawai TERTENTU
 * tetap memeriksa scope walau dipanggil dari jalur lain.
 */
class EmployeePolicy
{
    public function __construct(
        private readonly DataScope $scope,
        private readonly EmployeeUsage $usage,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->can('employees.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can('employees.view') && $this->scope->allows($user, $employee->id);
    }

    public function create(User $user): bool
    {
        return $user->can('employees.create');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can('employees.update') && $this->scope->allows($user, $employee->id);
    }

    /**
     * Izin + scope saja. Syarat BR-055 (belum dipakai) dicek DeleteEmployee agar penolakannya berupa
     * arahan "Gunakan Nonaktifkan", bukan 403; `deletable()` untuk menyembunyikan tombol di UI.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return $user->can('employees.delete') && $this->scope->allows($user, $employee->id);
    }

    public function deletable(User $user, Employee $employee): bool
    {
        return $this->delete($user, $employee) && $this->usage->canDelete($employee);
    }

    public function revealNik(User $user, Employee $employee): bool
    {
        return $user->can('employees.view_sensitive') && $this->scope->allows($user, $employee->id);
    }

    /** Buat akun dari pegawai: izin akun (`users.create`) + pegawai dalam scope. */
    public function createAccount(User $user, Employee $employee): bool
    {
        return $user->can('users.create') && $this->scope->allows($user, $employee->id);
    }
}
