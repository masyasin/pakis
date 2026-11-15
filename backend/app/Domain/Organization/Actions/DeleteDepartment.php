<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Support\DepartmentUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hapus departemen: hanya yang belum punya sub-departemen dan belum dipakai jabatan/absensi.
 * Yang sudah dipakai cukup dinonaktifkan agar data lama tetap menampilkan namanya.
 */
final class DeleteDepartment
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DepartmentUsage $usage,
    ) {}

    public function handle(Department $department, ?int $userId): void
    {
        DB::transaction(function () use ($department, $userId) {
            $locked = Department::query()->lockForUpdate()->findOrFail($department->id);

            if ($this->usage->isUsed($locked)) {
                throw ValidationException::withMessages([
                    'department' => "Departemen \"{$locked->name}\" masih punya sub-departemen atau sudah dipakai jabatan/presensi sehingga tidak bisa dihapus. Nonaktifkan saja.",
                ]);
            }

            $before = $locked->only(Department::AUDITED);
            $locked->updated_by = $userId;
            $locked->save();
            $locked->delete();

            $this->audit->record('department.deleted', $locked, $before, null);
        });
    }
}
