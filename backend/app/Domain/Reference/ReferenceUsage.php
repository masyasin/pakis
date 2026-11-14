<?php

namespace App\Domain\Reference;

use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apakah item referensi sudah dipakai data lain (BR-026: yang dipakai hanya bisa dinonaktifkan).
 *
 * Pemakai diambil dari ReferenceGroup::usages(). Tabelnya baru dibuat di T-021 (lokasi),
 * T-024 (pegawai), dan T-040 (koreksi); sebelum ada, pemakai itu dilewati. Setelah tabelnya ada,
 * FK `RESTRICT` di DB juga menolak penghapusan, jadi cek ini memberi pesan yang ramah, bukan
 * satu-satunya pengaman.
 */
class ReferenceUsage
{
    /**
     * @param  array<string, list<array{0: string, 1: string}>>|null  $usages  pemakai eksplisit per
     *                                                                         grup (untuk test); null = dari enum
     */
    public function __construct(private readonly ?array $usages = null) {}

    public function isUsed(ReferenceItem $item): bool
    {
        foreach ($this->usagesFor($item) as [$table, $column]) {
            if (DB::table($table)->where($column, $item->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function usagesFor(ReferenceItem $item): array
    {
        if ($this->usages !== null) {
            return $this->usages[$item->group_code] ?? [];
        }

        $usages = $item->group()?->usages() ?? [];

        return array_values(array_filter($usages, fn (array $usage) => Schema::hasTable($usage[0])));
    }
}
