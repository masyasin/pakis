<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Hapus kategori produk (soft delete): hanya yang belum punya sub-kategori dan belum dipakai
 * produk. Yang sudah dipakai cukup dinonaktifkan agar produk lama tetap menampilkan kategorinya
 * (kriteria T-069).
 */
final class DeleteProductCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ProductCategory $category, ?int $userId): void
    {
        DB::transaction(function () use ($category, $userId) {
            $locked = ProductCategory::query()->lockForUpdate()->findOrFail($category->id);

            if (self::isUsed($locked)) {
                throw ValidationException::withMessages([
                    'category' => "Kategori \"{$locked->name}\" masih punya sub-kategori atau sudah dipakai produk sehingga tidak bisa dihapus. Nonaktifkan saja.",
                ]);
            }

            $before = $locked->audited();
            $locked->updated_by = $userId;
            $locked->save();
            $locked->delete();

            $this->audit->record('product_category.deleted', $locked, $before, null);
        });
    }

    public static function isUsed(ProductCategory $category): bool
    {
        if ($category->children()->exists()) {
            return true;
        }

        // Tabel produk dibuat T-070; sebelum itu belum ada pemakai. Produk terhapus (soft) tetap
        // dihitung: FK dan riwayat dokumennya masih merujuk kategori ini.
        return Schema::hasTable('products')
            && DB::table('products')->where('product_category_id', $category->id)->exists();
    }
}
