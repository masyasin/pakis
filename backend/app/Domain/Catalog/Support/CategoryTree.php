<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Organization\Support\HierarchyGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan pohon kategori (FR-A06): tanpa siklus (HierarchyGuard dipakai ulang) dan maksimal
 * ProductCategory::MAX_DEPTH tingkat. Memindahkan kategori ikut memindahkan subpohonnya, jadi yang
 * diperiksa adalah kedalaman induk baru + tinggi subpohon kategori itu. Tabel kecil (puluhan
 * baris), jadi penelusuran per baris cukup.
 */
final class CategoryTree
{
    /** Kunci seluruh baris kategori: dua perubahan induk bersamaan tidak boleh lolos cek masing-masing. */
    public static function lock(): void
    {
        DB::table('product_categories')->lockForUpdate()->pluck('id');
    }

    /** @throws ValidationException bila induk membentuk siklus atau melewati batas tingkat */
    public static function assertParent(?int $categoryId, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($categoryId !== null && ($parentId === $categoryId || HierarchyGuard::wouldCycle('product_categories', 'parent_id', $categoryId, $parentId))) {
            throw ValidationException::withMessages([
                'parent_id' => 'Induk ini berada di bawah kategori yang sedang diubah, sehingga membentuk lingkaran. Pilih induk lain.',
            ]);
        }

        $height = $categoryId === null ? 1 : self::height($categoryId);
        if (self::depth($parentId) + $height > ProductCategory::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Kategori maksimal '.ProductCategory::MAX_DEPTH.' tingkat. Pilih induk yang lebih atas.',
            ]);
        }
    }

    /** Tingkat sebuah kategori dari akar (akar = 1). */
    public static function depth(int $categoryId): int
    {
        $depth = 0;
        for ($id = $categoryId; $id !== null && $depth <= ProductCategory::MAX_DEPTH + 1; $depth++) {
            $parent = DB::table('product_categories')->where('id', $id)->value('parent_id');
            $id = $parent === null ? null : (int) $parent;
        }

        return $depth;
    }

    /** Tinggi subpohon (kategori tanpa anak = 1). */
    public static function height(int $categoryId): int
    {
        $height = 1;
        $level = [$categoryId];
        while ($height <= ProductCategory::MAX_DEPTH + 1) {
            $level = DB::table('product_categories')->whereIn('parent_id', $level)->whereNull('deleted_at')->pluck('id')->all();
            if ($level === []) {
                break;
            }
            $height++;
        }

        return $height;
    }
}
