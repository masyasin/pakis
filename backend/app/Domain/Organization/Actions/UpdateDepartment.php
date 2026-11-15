<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Support\DepartmentHierarchy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Ubah departemen (FR-023). Induk tidak boleh membentuk siklus. */
final class UpdateDepartment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi DepartmentRequest (kunci = Department::AUDITED)
     */
    public function handle(Department $department, array $data, ?int $userId): Department
    {
        return DB::transaction(function () use ($department, $data, $userId) {
            // Kunci semua baris departemen (tabel kecil): dua perubahan induk yang berjalan bersamaan
            // (A→B dan B→A) masing-masing lolos cek siklus bila tidak diserialkan.
            DB::table('departments')->lockForUpdate()->pluck('id');
            $locked = Department::query()->findOrFail($department->id);
            $before = $locked->only(Department::AUDITED);

            $parentId = $data['parent_id'] ?? null;
            if ($parentId !== null && $parentId !== $locked->parent_id && DepartmentHierarchy::wouldCycle($locked->id, (int) $parentId)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Induk ini berada di bawah departemen yang sedang diubah, sehingga membentuk lingkaran. Pilih induk lain.',
                ]);
            }

            $locked->fill($data);
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('department.updated', $locked, $before, $locked->only(Department::AUDITED));

            return $locked;
        });
    }
}
