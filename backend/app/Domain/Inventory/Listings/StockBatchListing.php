<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Halaman Karantina (`admin.stock-batches.index`, katalog 07 §3.4 / 15 §7.3: `stock_batches`,
 * keputusan Mas PA-69): batch ber-stok di gudang/toko dalam scope Stok, dengan aksi karantina,
 * pindah rak, dan lepas karantina. Query dasar = `self::query()` (join `products` & `stock_bins`).
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

    /**
     * Query dasar halaman Karantina, dipakai juga kartu dasbor (T-087A) agar angkanya sama: batch
     * ber-stok (bukan `disposed`) di gudang/toko dalam scope Stok pengguna, join `products` & `stock_bins`.
     *
     * @return Builder<StockBatch>
     */
    public static function query(User $user): Builder
    {
        $query = StockBatch::query()
            ->select('stock_batches.*')
            ->join('products', 'products.id', '=', 'stock_batches.product_id')
            ->leftJoin('stock_bins', 'stock_bins.id', '=', 'stock_batches.stock_bin_id')
            ->where('stock_batches.qty_on_hand', '>', 0)
            ->where('stock_batches.status', '<>', StockBatchStatus::Disposed->value)
            ->whereIn('stock_batches.stock_location_id', fn ($q) => $q->select('id')->from('stock_locations')
                ->whereIn('type', [StockLocationType::Warehouse->value, StockLocationType::Store->value]));

        return app(DataScope::class)->forStock($query, $user, 'stock_batches.stock_location_id');
    }
}
