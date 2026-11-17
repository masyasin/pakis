<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Halaman Karantina (`admin.stock-batches.index`, katalog 07 §3.4 / 15 §7.3: `stock_batches`,
 * keputusan Mas PA-69): batch ber-stok di gudang/toko dalam scope Stok, dengan aksi karantina,
 * pindah rak, dan lepas karantina. Query dasar dari controller sudah join `products` & `stock_bins`.
 */
final class StockBatchListing implements HasListing
{
    public function spec(): ListingSpec
    {
        $statuses = [StockBatchStatus::Available, StockBatchStatus::Quarantine, StockBatchStatus::Expired];

        return ListingSpec::make('stock_batches')
            ->searchPlaceholder('Cari SKU, nama produk, atau batch')
            ->search(TextSearch::make()->prefix('stock_batches.batch_no')->prefix('products.sku')->contains('products.name'))
            ->filters([
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')->column('stock_batches.stock_location_id')
                    ->validIds(fn () => StockLocation::query()->whereIn('type', [StockLocationType::Warehouse->value, StockLocationType::Store->value])),
                MultiSelectFilter::make('status', 'Status')->column('stock_batches.status')
                    ->options(array_map(fn (StockBatchStatus $s) => ['value' => $s->value, 'label' => $s->label()], $statuses)),
                RelationFilter::make('stock_bin_id', 'Rak')->source('stock_bins')->column('stock_batches.stock_bin_id')
                    ->validIds(fn () => StockBin::query()),
            ])
            ->sorts([
                Sort::make('expiry_date', 'ED')->column('stock_batches.expiry_date'),
                Sort::make('product', 'Produk')->column('products.name'),
                Sort::make('batch_no', 'Batch')->column('stock_batches.batch_no'),
            ])
            ->defaultSort('expiry_date');
    }
}
