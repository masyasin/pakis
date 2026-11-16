<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\SalesArea;
use App\Domain\Organization\Support\HierarchyGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan pohon area penjualan (FR-A12, T-073): tanpa siklus (HierarchyGuard) dan maksimal
 * SalesArea::MAX_DEPTH tingkat. Memindahkan area ikut memindahkan sub-areanya, jadi yang diperiksa
 * kedalaman induk baru + tinggi subpohon area itu (pola CategoryTree T-069). Tabel kecil.
 */
final class SalesAreaTree
{
    /** Kunci seluruh baris area: dua perubahan induk bersamaan tidak boleh lolos cek masing-masing. */
    public static function lock(): void
    {
        DB::table('sales_areas')->lockForUpdate()->pluck('id');
    }

    /** @throws ValidationException bila induk membentuk siklus atau melewati batas tingkat */
    public static function assertParent(?int $areaId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($areaId !== null && ($parentId === $areaId || HierarchyGuard::wouldCycle('sales_areas', 'parent_id', $areaId, $parentId))) {
            throw ValidationException::withMessages([
                'parent_id' => 'Induk ini berada di bawah area yang sedang diubah, sehingga membentuk lingkaran. Pilih induk lain.',
            ]);
        }

        $height = $areaId === null ? 1 : self::height($areaId);
        if (self::depth($parentId) + $height > SalesArea::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Area penjualan maksimal '.SalesArea::MAX_DEPTH.' tingkat. Pilih induk yang lebih atas.',
            ]);
        }
    }

    /** Tingkat area dari akar (akar = 1). */
    public static function depth(int $areaId): int
    {
        $depth = 0;
        for ($id = $areaId; $id !== null && $depth <= SalesArea::MAX_DEPTH + 1; $depth++) {
            $parent = DB::table('sales_areas')->where('id', $id)->value('parent_id');
            $id = $parent === null ? null : (int) $parent;
        }

        return $depth;
    }

    /** Tinggi subpohon (area tanpa sub-area = 1). */
    public static function height(int $areaId): int
    {
        $height = 1;
        $level = [$areaId];
        while ($height <= SalesArea::MAX_DEPTH + 1) {
            $level = DB::table('sales_areas')->whereIn('parent_id', $level)->whereNull('deleted_at')->pluck('id')->all();
            if ($level === []) {
                break;
            }
            $height++;
        }

        return $height;
    }

    /**
     * Area ini + semua sub-areanya (untuk menyembunyikan pilihan induk yang pasti membentuk siklus).
     *
     * @return list<int>
     */
    public static function withDescendants(int $areaId): array
    {
        $all = [$areaId];
        $level = [$areaId];
        for ($i = 0; $i <= SalesArea::MAX_DEPTH + 1 && $level !== []; $i++) {
            $level = DB::table('sales_areas')->whereIn('parent_id', $level)->whereNotIn('id', $all)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $all = [...$all, ...$level];
        }

        return $all;
    }
}
