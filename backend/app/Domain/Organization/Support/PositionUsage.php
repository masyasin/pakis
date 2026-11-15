<?php

namespace App\Domain\Organization\Support;

use App\Domain\Organization\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah jabatan sudah dipakai (T-023): punya jabatan bawahan (termasuk yang terhapus, karena FK tetap
 * menunjuk), dipakai penugasan (T-025) atau absensi harian (T-036). Yang dipakai hanya bisa
 * dinonaktifkan. Pola sama dengan LocationUsage.
 */
class PositionUsage
{
    /** @var list<array{0: string, 1: string}> [tabel, kolom] dari 04 */
    public const USAGES = [
        ['positions', 'parent_position_id'],
        ['assignments', 'position_id'],
        ['attendance_days', 'position_id'],
    ];

    /**
     * @param  list<array{0: string, 1: string}>|null  $usages  pemakai eksplisit (untuk test); null = USAGES
     */
    public function __construct(private readonly ?array $usages = null) {}

    public function isUsed(Position $position): bool
    {
        // Pemakai eksplisit (test) tidak dicek keberadaan tabelnya: MariaDB tidak menampilkan
        // TEMPORARY TABLE di information_schema.
        $usages = $this->usages ?? array_filter(self::USAGES, fn (array $usage) => Schema::hasTable($usage[0]));

        foreach ($usages as [$table, $column]) {
            if (DB::table($table)->where($column, $position->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
