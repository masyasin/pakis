<?php

namespace App\Providers;

use App\Domain\Catalog\Lookup\ProductCategoryLookup;
use App\Domain\Catalog\Lookup\ProductLookup;
use App\Domain\Catalog\Lookup\ProductUnitLookup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Policies\ProductCategoryPolicy;
use App\Domain\Catalog\Policies\ProductPolicy;
use App\Support\Lookup\LookupRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Katalog Paket A (T-069 dst.: kategori, produk, izin edar). Provider terpisah, pola
 * ApprovalServiceProvider, agar modul ini tidak menyentuh registrasi modul lain.
 */
class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(LookupRegistry::class, function (LookupRegistry $registry) {
            $registry->register('product_categories', ProductCategoryLookup::class);
            $registry->register('products', ProductLookup::class);
            $registry->register('product_units', ProductUnitLookup::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        Gate::policy(ProductCategory::class, ProductCategoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
    }
}
