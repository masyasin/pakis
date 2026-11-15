<?php

namespace App\Domain\Employee\Policies;

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Support\ScopeResolver;
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

    /** Nonaktifkan pegawai (BR-053): izin `employees.offboard` + pegawai dalam scope. */
    public function offboard(User $user, Employee $employee): bool
    {
        return $user->can('employees.offboard') && $this->scope->allows($user, $employee->id);
    }

    /** Aktifkan kembali (06 §7.3): izin yang sama dengan menonaktifkan. */
    public function rehire(User $user, Employee $employee): bool
    {
        return $this->offboard($user, $employee);
    }

    /** Mutasi / tambah penugasan tambahan untuk pegawai ini (T-025). */
    public function manageAssignments(User $user, Employee $employee): bool
    {
        return $user->can('assignments.manage') && $this->scope->allows($user, $employee->id);
    }

    /**
     * Buat akun dari pegawai: izin akun (`users.create`) + scope `all`. Menautkan akun ke pegawai
     * menentukan data yang terlihat akun itu; pelaku ber-scope sempit bisa memilih pegawai yang
     * mengepalai lokasi di luar scope-nya (aturan sama dengan UserRequest::checkEmployeeLink).
     */
    public function createAccount(User $user, Employee $employee): bool
    {
        return $user->can('users.create') && ScopeResolver::for($user) === Scope::All && $this->scope->allows($user, $employee->id);
    }
}
