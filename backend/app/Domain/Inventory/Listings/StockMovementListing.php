<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Support\SourceDocuments;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Export\UserScopedExportColumns;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kartu stok (`admin.stock-movements.index`, katalog 07 §3.4 / 15 §7.3: `stock_movements`). Mutasi
 * append-only (BR-154) per batch, dalam scope Stok lokasi mutasi. `business_date` WAJIB (bawaan bulan
 * berjalan, maks 366 hari) agar kartu 1 juta mutasi tetap memakai index `smov_product_loc_date_idx`
 * (NFR-A04). Pencarian: nomor dokumen sumber (PO/GR/mutasi/opname/BA/retur) atau nomor batch. Biaya
 * mutasi (`unit_cost`) hanya untuk `stock.view_cost` (SEC-107).
 */
final class StockMovementListing implements ExportableListing, UserScopedExportColumns
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_movements')
            ->searchPlaceholder('Cari nomor dokumen atau nomor batch')
            ->search(TextSearch::make()
                ->using(function (Builder $q, string $word): void {
                    $q->orWhere('stock_batches.batch_no', 'like', TextSearch::escapeLike($word).'%');
                })
                ->using(function (Builder $q, string $word): void {
                    SourceDocuments::whereNumberLike($q, TextSearch::escapeLike($word).'%');
                }))
            ->filters([
                DateRangeFilter::make('business_date', 'Tanggal')->column('stock_movements.business_date')
                    ->default(fn () => [
                        'from' => DisplayTime::toLocal(now())->startOfMonth()->toDateString(),
                        'to' => DisplayTime::toLocal(now())->toDateString(),
                    ])->required(),
                RelationFilter::make('product_id', 'Produk')->source('products')->column('stock_movements.product_id')
                    ->validIds(fn () => Product::query()),
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')->column('stock_movements.stock_location_id')
                    ->validIds(fn () => StockLocation::query()),
                MultiSelectFilter::make('movement_type', 'Jenis mutasi')->column('stock_movements.movement_type')
                    ->options(array_map(fn (MovementType $t) => ['value' => $t->value, 'label' => $t->label()], MovementType::cases())),
            ])
            ->sorts([
                Sort::make('moved_at', 'Waktu')->column('stock_movements.moved_at'),
                Sort::make('business_date', 'Tanggal')->column('stock_movements.business_date'),
            ])
            ->defaultSort('-moved_at')
            ->exportable();
    }

    /** @return Builder<StockMovement> */
    public static function base(User $user): Builder
    {
        $query = StockMovement::query()
            ->select('stock_movements.*')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_movements.stock_batch_id');

        return app(DataScope::class)->forStock($query, $user, 'stock_movements.stock_location_id');
    }

    public function exportQuery(User $user): Builder
    {
        return self::base($user)->with(['batch:id,batch_no,expiry_date,product_id,stock_location_id', 'batch.product:id,sku,name', 'batch.stockLocation:id,name']);
    }

    public function exportColumns(): ExportColumns
    {
        return self::columns(false);
    }

    public function exportColumnsFor(User $user): ExportColumns
    {
        return self::columns($user->can('stock.view_cost'));
    }

    /** @return ExportColumns<StockMovement> */
    private static function columns(bool $withCost): ExportColumns
    {
        $columns = ExportColumns::for(StockMovement::class)
            ->add('Waktu', fn (StockMovement $m) => DisplayTime::toLocal($m->moved_at)->format('Y-m-d H:i'))
            ->add('Tanggal bisnis', fn (StockMovement $m) => $m->business_date->toDateString())
            ->add('SKU', fn (StockMovement $m) => $m->batch->product->sku)
            ->add('Produk', fn (StockMovement $m) => $m->batch->product->name)
            ->add('Lokasi', fn (StockMovement $m) => $m->batch->stockLocation->name)
            ->add('Batch', fn (StockMovement $m) => $m->batch->batch_no)
            ->add('Jenis', fn (StockMovement $m) => $m->movement_type->label())
            ->add('Qty', fn (StockMovement $m) => $m->qty)
            ->add('Saldo', fn (StockMovement $m) => $m->qty_after)
            ->add('Dokumen', fn (StockMovement $m) => "{$m->source_type}#{$m->source_id}");

        return $withCost ? $columns->add('Biaya satuan', fn (StockMovement $m) => $m->unit_cost) : $columns;
    }
}
