<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockOpname;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lembar hitung (`admin.stock-opnames.lines.index`, katalog 07 §3.4 / 15 §7.3:
 * `stock_opname_lines`). Opname dari parameter rute (bukan filter). Query dasar dari controller
 * sudah join `products` & `stock_bins` (pencarian & urutan) dan `stock_opnames` (`frozen_at`).
 *
 * `has_variance` TIDAK tersedia saat hitung buta & `counting` (SEC-103): menyaring "ada selisih"
 * sama saja membocorkan angka sistem, jadi spec dibuat tanpa filter itu (`$withVariance = false`).
 */
final class StockOpnameLineListing implements HasListing
{
    /**
     * Selisih ≠ 0: hitung final ≠ snapshot + Σ mutasi batch setelah `frozen_at` di luar opname ini
     * (BR-162(2)). SQL tetap tanpa input pengguna.
     */
    private const VARIANCE_SQL = 'COALESCE(stock_opname_lines.qty_recount_base, stock_opname_lines.qty_counted_base) <> stock_opname_lines.qty_system_base + COALESCE((
        SELECT SUM(m.qty) FROM stock_movements m
        WHERE m.stock_batch_id = stock_opname_lines.stock_batch_id
          AND m.moved_at > stock_opnames.frozen_at
          AND NOT (m.source_type = \''.StockOpname::SOURCE_TYPE.'\' AND m.source_id = stock_opnames.id)
    ), 0)';

    public function __construct(private readonly bool $withVariance = true) {}

    public function spec(): ListingSpec
    {
        $filters = [
            RelationFilter::make('stock_bin_id', 'Rak')->source('stock_bins')->column('stock_opname_lines.stock_bin_id')
                ->validIds(fn () => StockBin::query()),
            BooleanFilter::make('counted', 'Sudah dihitung')
                ->using(fn (Builder $q, mixed $v) => (bool) $v
                    ? $q->whereNotNull('stock_opname_lines.qty_counted_base')
                    : $q->whereNull('stock_opname_lines.qty_counted_base')),
        ];
        if ($this->withVariance) {
            $filters[] = BooleanFilter::make('has_variance', 'Ada selisih')
                ->using(fn (Builder $q, mixed $v) => (bool) $v
                    ? $q->whereNotNull('stock_opname_lines.qty_counted_base')->whereRaw(self::VARIANCE_SQL)
                    : $q->where(fn (Builder $w) => $w->whereNull('stock_opname_lines.qty_counted_base')->orWhereRaw('NOT ('.self::VARIANCE_SQL.')')));
        }

        return ListingSpec::make('stock_opname_lines')
            ->searchPlaceholder('Cari SKU, nama produk, atau batch')
            ->search(TextSearch::make()->prefix('stock_opname_lines.batch_no')->prefix('products.sku')->contains('products.name'))
            ->filters($filters)
            ->sorts([
                Sort::make('bin', 'Rak')->column('stock_bins.code'),
                Sort::make('product', 'Produk')->column('products.name'),
            ])
            ->defaultSort('bin');
    }
}
