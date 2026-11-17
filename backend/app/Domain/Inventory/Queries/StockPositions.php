<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\StockBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Query dasar posisi stok (15 §7.3 `stock_positions`, FR-A30), dua mode:
 *
 * - `per_batch`: satu baris per batch bersaldo (model StockBatch), join produk/lokasi/rak.
 * - `per_product`: satu baris per produk × lokasi stok (model Product + subquery agregat batch alias
 *   `pos`), dengan qty tersedia (batch `available` − reservasi), karantina, kedaluwarsa, ED terdekat.
 *
 * Scope Stok diterapkan pada kolom lokasi batch (DataScope::forStock) di kedua mode. Filter listing
 * membedakan mode lewat `isBatchMode()` (model query), sehingga satu spec melayani keduanya.
 */
final class StockPositions
{
    public const PER_PRODUCT = 'per_product';

    public const PER_BATCH = 'per_batch';

    /** @return Builder<StockBatch> */
    public static function perBatch(User $user): Builder
    {
        $query = StockBatch::query()
            ->select('stock_batches.*')
            ->join('products', 'products.id', '=', 'stock_batches.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_batches.stock_location_id')
            ->leftJoin('stock_bins', 'stock_bins.id', '=', 'stock_batches.stock_bin_id')
            ->where('stock_batches.qty_on_hand', '>', 0);

        return app(DataScope::class)->forStock($query, $user, 'stock_batches.stock_location_id');
    }

    /** @return Builder<Product> */
    public static function perProduct(User $user): Builder
    {
        $agg = DB::table('stock_batches')
            ->selectRaw(
                'product_id, stock_location_id, SUM(qty_on_hand) AS qty_on_hand, SUM(qty_reserved) AS qty_reserved, '
                .'SUM(CASE WHEN status = ? THEN qty_on_hand - qty_reserved ELSE 0 END) AS qty_available, '
                .'SUM(CASE WHEN status = ? THEN qty_on_hand ELSE 0 END) AS qty_quarantine, '
                .'SUM(CASE WHEN status = ? THEN qty_on_hand ELSE 0 END) AS qty_expired, '
                .'MIN(expiry_date) AS next_expiry',
                [StockBatchStatus::Available->value, StockBatchStatus::Quarantine->value, StockBatchStatus::Expired->value],
            )
            ->where('qty_on_hand', '>', 0)
            ->groupBy('product_id', 'stock_location_id');
        app(DataScope::class)->forStock($agg, $user, 'stock_batches.stock_location_id');

        return Product::query()
            ->joinSub($agg, 'pos', 'pos.product_id', '=', 'products.id')
            ->join('stock_locations', 'stock_locations.id', '=', 'pos.stock_location_id')
            ->select('products.*', 'pos.stock_location_id', 'pos.qty_on_hand', 'pos.qty_reserved', 'pos.qty_available',
                'pos.qty_quarantine', 'pos.qty_expired', 'pos.next_expiry', 'stock_locations.code as location_code', 'stock_locations.name as location_name');
    }

    /** @param  Builder<*>  $query */
    public static function isBatchMode(Builder $query): bool
    {
        return $query->getModel() instanceof StockBatch;
    }

    /**
     * Kolom lokasi stok sesuai mode (dipakai filter & widget).
     *
     * @param  Builder<*>  $query
     */
    public static function locationColumn(Builder $query): string
    {
        return self::isBatchMode($query) ? 'stock_batches.stock_location_id' : 'pos.stock_location_id';
    }
}
