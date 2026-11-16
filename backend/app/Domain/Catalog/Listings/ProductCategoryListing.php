<?php

namespace App\Domain\Catalog\Listings;

use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Models\ProductCategory;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar kategori produk (`admin.product-categories.index`, katalog 07 §3.4 / 15 §7.3:
 * `product_categories`). Master tanpa scope data (06 §5); hak lihat = `products.view`.
 */
final class ProductCategoryListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('product_categories')
            ->searchPlaceholder('Cari kode atau nama')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                SelectFilter::make('kind', 'Jenis')->column('kind')->options(CategoryKind::options()),
                RelationFilter::make('parent', 'Induk')
                    ->source('product_categories')
                    ->column('parent_id')
                    ->validIds(fn () => ProductCategory::query()),
                BooleanFilter::make('active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('sort_order', 'Urutan'),
                Sort::make('name', 'Nama'),
                Sort::make('code', 'Kode'),
            ])
            ->defaultSort('sort_order');
    }
}
