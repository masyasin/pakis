<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Support\CategoryTree;
use Illuminate\Support\Facades\DB;

/** Tambah kategori produk (FR-A06, BR-151). Kategori baru belum punya anak: cukup cek tingkat induk. */
final class CreateProductCategory
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi ProductCategoryRequest */
    public function handle(array $data, ?int $userId): ProductCategory
    {
        return DB::transaction(function () use ($data, $userId) {
            CategoryTree::lock();
            CategoryTree::assertParent(null, isset($data['parent_id']) ? (int) $data['parent_id'] : null);

            $category = new ProductCategory($data);
            $category->created_by = $userId;
            $category->updated_by = $userId;
            $category->save();

            $this->audit->record('product_category.created', $category, null, $category->audited());

            return $category;
        });
    }
}
