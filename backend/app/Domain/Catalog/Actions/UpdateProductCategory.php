<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Support\CategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * Ubah kategori produk (FR-A06, BR-151). Memindah induk memeriksa siklus & batas tingkat untuk
 * seluruh subpohon. Mengubah penanda perilaku tidak mengubah produk yang sudah ada: penanda
 * disalin ke produk saat produk dibuat (T-070).
 */
final class UpdateProductCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi ProductCategoryRequest */
    public function handle(ProductCategory $category, array $data, ?int $userId): ProductCategory
    {
        return DB::transaction(function () use ($category, $data, $userId) {
            CategoryTree::lock();
            $locked = ProductCategory::query()->findOrFail($category->id);
            $before = $locked->audited();

            $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            if ($parentId !== $locked->parent_id) {
                CategoryTree::assertParent($locked->id, $parentId);
            }

            $locked->fill($data);
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('product_category.updated', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
