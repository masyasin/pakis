<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Queries\StockPositions;
use App\Domain\Purchasing\Models\Principal;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Export\UserScopedExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Posisi stok (`admin.stock.index`, katalog 07 §3.4 / 15 §7.3: `stock_positions`, FR-A30). Filter
 * `view` memilih mode (per produk/per batch; query dasar dipilih controller dari nilai yang sama).
 * Filter batch-level (status, ED, zona, nomor batch) pada mode per produk diterjemahkan ke
 * `EXISTS` batch produk-lokasi itu. Ekspor selalu per batch (detail audit), kolom HPP/nilai hanya
 * untuk `stock.view_cost` (SEC-107, PA-72).
 */
final class StockPositionListing implements ExportableListing, UserScopedExportColumns
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_positions')
            ->searchPlaceholder('Cari SKU, nama produk, atau nomor batch')
            ->search(TextSearch::make()->prefix('products.sku')->contains('products.name')
                ->using(function (Builder $q, string $word): void {
                    $pattern = TextSearch::escapeLike($word).'%';
                    StockPositions::isBatchMode($q)
                        ? $q->orWhere('stock_batches.batch_no', 'like', $pattern)
                        : $q->orWhereExists(fn ($e) => self::batchesOfRow($e)->where('b.batch_no', 'like', $pattern));
                }))
            ->filters([
                SelectFilter::make('view', 'Tampilan')
                    ->options([['value' => StockPositions::PER_PRODUCT, 'label' => 'Per produk'], ['value' => StockPositions::PER_BATCH, 'label' => 'Per batch']])
                    ->default(StockPositions::PER_PRODUCT)->required()
                    ->using(fn () => null), // mode dipakai controller memilih query dasar
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')
                    ->validIds(fn () => StockLocation::query())
                    ->using(fn (Builder $q, mixed $ids) => $q->whereIn(StockPositions::locationColumn($q), (array) $ids)),
                RelationFilter::make('category_id', 'Kategori')->source('product_categories')->column('products.product_category_id')
                    ->validIds(fn () => ProductCategory::query()),
                RelationFilter::make('principal_id', 'Prinsipal')->source('principals')->column('products.principal_id')
                    ->validIds(fn () => Principal::query()),
                MultiSelectFilter::make('status', 'Status batch')
                    ->options(array_map(fn (StockBatchStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                        [StockBatchStatus::Available, StockBatchStatus::Quarantine, StockBatchStatus::Expired]))
                    ->using(fn (Builder $q, mixed $v) => StockPositions::isBatchMode($q)
                        ? $q->whereIn('stock_batches.status', (array) $v)
                        : $q->whereExists(fn ($e) => self::batchesOfRow($e)->whereIn('b.status', (array) $v))),
                DateRangeFilter::make('expiry_before', 'ED sampai')
                    ->using(function (Builder $q, mixed $v): void {
                        $to = is_array($v) ? ($v['to'] ?? null) : null;
                        $from = is_array($v) ? ($v['from'] ?? null) : null;
                        $column = StockPositions::isBatchMode($q) ? 'stock_batches.expiry_date' : 'pos.next_expiry';
                        $q->whereNotNull($column)
                            ->when($from !== null, fn ($w) => $w->where($column, '>=', $from))
                            ->when($to !== null, fn ($w) => $w->where($column, '<=', $to));
                    }),
                BooleanFilter::make('below_min', 'Di bawah stok minimum')
                    ->using(fn (Builder $q, mixed $v) => (bool) $v ? self::belowMin($q) : $q),
                MultiSelectFilter::make('zone', 'Zona rak')
                    ->options(array_map(fn (BinZone $z) => ['value' => $z->value, 'label' => $z->label()], BinZone::cases()))
                    ->using(fn (Builder $q, mixed $v) => StockPositions::isBatchMode($q)
                        ? $q->whereIn('stock_bins.zone', (array) $v)
                        : $q->whereExists(fn ($e) => self::batchesOfRow($e)->join('stock_bins as zb', 'zb.id', '=', 'b.stock_bin_id')->whereIn('zb.zone', (array) $v))),
            ])
            ->sorts([
                Sort::make('product', 'Produk')->column('products.name'),
                Sort::make('sku', 'SKU')->column('products.sku'),
            ])
            ->defaultSort('product')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return StockPositions::perBatch($user)->with(['product:id,sku,name', 'stockLocation:id,code,name', 'bin:id,code,zone']);
    }

    /** Tanpa pengguna (antrean lama): aman = tanpa kolom biaya. */
    public function exportColumns(): ExportColumns
    {
        return self::columns(false);
    }

    public function exportColumnsFor(User $user): ExportColumns
    {
        return self::columns($user->can('stock.view_cost'));
    }

    /** @return ExportColumns<StockBatch> */
    private static function columns(bool $withCost): ExportColumns
    {
        $columns = ExportColumns::for(StockBatch::class)
            ->add('SKU', fn (StockBatch $b) => $b->product->sku)
            ->add('Produk', fn (StockBatch $b) => $b->product->name)
            ->add('Lokasi', fn (StockBatch $b) => $b->stockLocation->name)
            ->add('Rak', fn (StockBatch $b) => $b->bin?->code)
            ->add('Batch', fn (StockBatch $b) => $b->batch_no)
            ->add('ED', fn (StockBatch $b) => $b->expiry_date?->toDateString())
            ->add('Status', fn (StockBatch $b) => $b->status->label())
            ->add('On hand', fn (StockBatch $b) => $b->qty_on_hand)
            ->add('Direservasi', fn (StockBatch $b) => $b->qty_reserved);
        if (! $withCost) {
            return $columns;
        }

        return $columns
            ->add('HPP rata-rata', fn (StockBatch $b) => self::avgCost($b->product_id))
            ->add('Nilai', fn (StockBatch $b) => (string) Money::of(BigDecimal::of($b->qty_on_hand)->multipliedBy(self::avgCost($b->product_id))));
    }

    /** @var array<int, string> */
    private static array $costs = [];

    private static function avgCost(int $productId): string
    {
        return self::$costs[$productId] ??= (string) (ProductCost::query()->where('product_id', $productId)->value('avg_cost') ?? '0');
    }

    /**
     * Subquery batch milik baris per produk (produk × lokasi `pos`).
     *
     * @param  \Illuminate\Database\Query\Builder  $exists
     */
    private static function batchesOfRow($exists): \Illuminate\Database\Query\Builder
    {
        return $exists->from('stock_batches as b')->selectRaw('1')
            ->whereColumn('b.product_id', 'products.id')
            ->whereColumn('b.stock_location_id', 'pos.stock_location_id')
            ->where('b.qty_on_hand', '>', 0);
    }

    /**
     * Saldo produk di lokasi di bawah `product_stock_levels.min_qty_base`.
     *
     * @param  Builder<*>  $q
     * @return Builder<*>
     */
    private static function belowMin(Builder $q): Builder
    {
        if (StockPositions::isBatchMode($q)) {
            return $q->whereExists(fn ($e) => $e->from('product_stock_levels as l')->selectRaw('1')
                ->whereColumn('l.product_id', 'stock_batches.product_id')
                ->whereColumn('l.stock_location_id', 'stock_batches.stock_location_id')
                ->whereRaw('l.min_qty_base > (SELECT COALESCE(SUM(s.qty_on_hand), 0) FROM stock_batches s WHERE s.product_id = stock_batches.product_id AND s.stock_location_id = stock_batches.stock_location_id)'));
        }

        return $q->whereExists(fn ($e) => $e->from('product_stock_levels as l')->selectRaw('1')
            ->whereColumn('l.product_id', 'products.id')
            ->whereColumn('l.stock_location_id', 'pos.stock_location_id')
            ->whereColumn('l.min_qty_base', '>', 'pos.qty_on_hand'));
    }

    /**
     * Scope Stok untuk validasi lokasi di form (dipakai controller).
     *
     * @return Builder<StockLocation>
     */
    public static function scopedLocations(User $user): Builder
    {
        return app(DataScope::class)->forStock(StockLocation::query(), $user, 'stock_locations.id');
    }
}
