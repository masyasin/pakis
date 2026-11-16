<?php

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\Product;
use App\Models\User;

/** Produk (15 §6): `products.view` / `products.manage`, master tanpa scope data. */
class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->can('products.view');
    }

    public function create(User $user): bool
    {
        return $user->can('products.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->can('products.manage');
    }
}
