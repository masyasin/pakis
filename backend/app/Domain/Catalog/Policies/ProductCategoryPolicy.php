<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\ProductCategory;
use App\Models\User;

/** Kategori produk (15 §6): `products.view` / `products.manage`, tanpa scope data. */
class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.manage');
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $user->can('products.manage');
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $user->can('products.manage');
    }
}
