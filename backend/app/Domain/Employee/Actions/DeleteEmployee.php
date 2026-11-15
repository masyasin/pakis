<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Employee\Models\Employee;
use App\Domain\Employee\Support\EmployeeUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus pegawai (soft delete, BR-055) — hanya untuk salah input. Akun terkait yang belum pernah
 * dipakai login ikut dinonaktifkan & dihapus agar tidak tersisa akun yatim atas nama pegawai itu.
 * Penugasannya dibiarkan (riwayat; query pegawai selalu menyaring yang terhapus).
 */
final class DeleteEmployee
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeUsage $usage,
    ) {}

    public function handle(User $actor, Employee $employee): void
    {
        DB::transaction(function () use ($actor, $employee) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);

            $blockers = $this->usage->blockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages([
                    'employee' => 'Pegawai ini tidak bisa dihapus karena '.implode(', ', $blockers).'. Gunakan Nonaktifkan.',
                ]);
            }

            $account = User::query()->where('employee_id', $locked->id)->first();
            if ($account !== null) {
                $account->forceFill(['is_active' => false, 'updated_by' => $actor->id])->save();
                $account->delete();
            }

            $locked->forceFill(['updated_by' => $actor->id])->save();
            $locked->delete();

            $this->audit->record('employee.deleted', $locked, CreateEmployee::audited($locked), [
                'deleted' => true,
                'account_removed' => $account?->username,
            ]);
        });
    }
}
