<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar nomor seri (`admin.serials.index`, katalog 07 §3.4 / 15 §7.3 `serial_numbers`, `stock.view`).
 * Hanya nomor seri yang sudah masuk stok (`stock_batch_id` terisi, PA-65); scope Stok lewat lokasi
 * batch (`scopedQuery`). Filter lokasi stok juga lewat batch.
 */
final class SerialNumberListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('serial_numbers')
            ->searchPlaceholder('Cari nomor seri atau SKU')
            ->search(TextSearch::make()->prefix('serial_no')->relation('product', 'sku'))
            ->filters([
                RelationFilter::make('product_id', 'Produk')->source('products')->column('serial_numbers.product_id')
                    ->validIds(fn () => Product::query()->withTrashed()),
                MultiSelectFilter::make('status', 'Status')->column('serial_numbers.status')->options(SerialStatus::options()),
                RelationFilter::make('stock_location_id', 'Lokasi stok')->source('stock_locations')
                    ->validIds(fn () => StockLocation::query())
                    ->using(fn (Builder $query, mixed $value) => $query->whereHas('batch', fn (Builder $b) => $b->where('stock_batches.stock_location_id', $value))),
            ])
            ->sorts([
                Sort::make('created_at', 'Dicatat')->column('serial_numbers.created_at'),
                Sort::make('serial_no', 'Nomor seri')->column('serial_numbers.serial_no'),
            ])
            ->defaultSort('-created_at')
            ->exportable();
    }

    /**
     * Query dasar daftar & ekspor: sudah diposting + scope Stok pengguna lewat lokasi batch.
     *
     * @return Builder<SerialNumber>
     */
    public static function scopedQuery(User $user): Builder
    {
        return SerialNumber::query()->with(['product:id,sku,name', 'batch.stockLocation:id,name', 'batch.bin:id,code'])
            ->whereNotNull('serial_numbers.stock_batch_id')
            ->whereHas('batch', fn (Builder $b) => app(DataScope::class)->forStock($b, $user, 'stock_batches.stock_location_id'));
    }

    public function exportQuery(User $user): Builder
    {
        return self::scopedQuery($user);
    }

    /** @return ExportColumns<SerialNumber> */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(SerialNumber::class)
            ->add('Nomor seri', fn (SerialNumber $s) => $s->serial_no)
            ->add('SKU', fn (SerialNumber $s) => $s->product->sku)
            ->add('Produk', fn (SerialNumber $s) => $s->product->name)
            ->add('Lokasi stok', fn (SerialNumber $s) => $s->batch?->stockLocation->name)
            ->add('Rak', fn (SerialNumber $s) => $s->batch?->bin?->code)
            ->add('Batch', fn (SerialNumber $s) => $s->batch?->batch_no)
            ->add('Status', fn (SerialNumber $s) => $s->status->label())
            ->add('Cara input', fn (SerialNumber $s) => $s->input_method->label())
            ->add('Dicatat', fn (SerialNumber $s) => $s->created_at?->toIso8601String());
    }
}
