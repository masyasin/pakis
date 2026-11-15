<?php

namespace App\Domain\Organization\Support;

use App\Domain\Organization\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah departemen sudah dipakai (T-022): punya sub-departemen (termasuk yang terhapus, karena FK
 * tetap menunjuk), dipakai jabatan (T-023) atau absensi harian (T-036). Yang dipakai hanya bisa
 * dinonaktifkan. Pola sama dengan LocationUsage.
 */
class DepartmentUsage
{
    /** @var list<array{0: string, 1: string}> [tabel, kolom] dari 04 */
    public const USAGES = [
        ['departments', 'parent_id'],
        ['positions', 'department_id'],
        ['attendance_days', 'department_id'],
    ];

    /**
     * @param  list<array{0: string, 1: string}>|null  $usages  pemakai eksplisit (untuk test); null = USAGES
     */
    public function __construct(private readonly ?array $usages = null) {}

    public function isUsed(Department $department): bool
    {
        // Pemakai eksplisit (test) tidak dicek keberadaan tabelnya: MariaDB tidak menampilkan
        // TEMPORARY TABLE di information_schema.
        $usages = $this->usages ?? array_filter(self::USAGES, fn (array $usage) => Schema::hasTable($usage[0]));

        foreach ($usages as [$table, $column]) {
            if (DB::table($table)->where($column, $department->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
