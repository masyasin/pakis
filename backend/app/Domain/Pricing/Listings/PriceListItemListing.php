<?php

namespace App\Domain\Pricing\Listings;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Item satu versi daftar harga (`admin.price-lists.items.index`, katalog 07 §3.4 / 15 §7.3:
 * `price_list_items`). Filter `version` WAJIB secara efektif: controller selalu mengisinya dari
 * parameter rute (juga untuk ekspor, karena job antrean hanya membawa nilai filter). Lihat & ekspor
 * = `prices.view` (master, PA-3).
 */
final class PriceListItemListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('price_list_items')
            ->searchPlaceholder('Cari SKU atau nama produk')
            ->search(TextSearch::make()
                ->relation('product', 'sku')
                ->relation('product', 'name', prefix: false))
            ->filters([
                RelationFilter::make('version', 'Versi')
                    ->source('price_list_versions')
                    ->column('price_list_items.price_list_version_id')
                    ->validIds(fn () => PriceListVersion::query()),
                RelationFilter::make('category_id', 'Kategori')
                    ->source('product_categories')
                    ->validIds(fn () => ProductCategory::query())
                    ->using(fn (Builder $query, mixed $ids) => $query->whereIn(
                        'price_list_items.product_id',
                        Product::withTrashed()->whereIn('product_category_id', (array) $ids)->select('id'),
                    )),
            ])
            ->sorts([
                Sort::make('name', 'Nama produk')->using(fn (Builder $q, string $dir) => $q->orderBy(
                    Product::withTrashed()->select('name')->whereColumn('products.id', 'price_list_items.product_id')->limit(1),
                    $dir,
                )->orderBy('price_list_items.min_qty')),
            ])
            ->defaultSort('name')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return PriceListItem::query()->with(['product:id,sku,name', 'productUnit.unit:id,name', 'version.priceList:id,code,name']);
    }

    /**
     * @return ExportColumns<PriceListItem>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(PriceListItem::class)
            ->add('Daftar harga', fn (PriceListItem $i) => $i->version->priceList->code)
            ->add('Versi', fn (PriceListItem $i) => $i->version->version_no)
            ->add('SKU', fn (PriceListItem $i) => $i->product->sku)
            ->add('Produk', fn (PriceListItem $i) => $i->product->name)
            ->add('Satuan', fn (PriceListItem $i) => $i->productUnit->unitLabel())
            ->add('Jumlah minimal', fn (PriceListItem $i) => $i->min_qty)
            ->add('Harga', fn (PriceListItem $i) => $i->price);
    }
}
