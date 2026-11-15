<?php

namespace App\Domain\Organization\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Organization\Models\Department;
use Illuminate\Support\Facades\DB;

/** Tambah departemen (FR-023). Departemen baru belum punya anak, jadi tidak mungkin membentuk siklus. */
final class CreateDepartment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi DepartmentRequest (kunci = Department::AUDITED)
     */
    public function handle(array $data, ?int $userId): Department
    {
        return DB::transaction(function () use ($data, $userId) {
            $department = Department::query()->create([...$data, 'created_by' => $userId, 'updated_by' => $userId]);

            $this->audit->record('department.created', $department, null, $department->only(Department::AUDITED));

            return $department;
        });
    }
}
