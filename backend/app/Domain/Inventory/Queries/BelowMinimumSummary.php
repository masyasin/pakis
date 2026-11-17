<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\ProductStockLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Widget dasbor "Stok di bawah minimum" (15 §7.4, T-087): pasangan produk × lokasi stok dalam scope
 * Stok yang saldonya di bawah `product_stock_levels.min_qty_base`, 10 terbawah menurut rasio
 * saldo / minimum (yang paling kosong lebih dulu). Saldo = Σ `qty_on_hand` batch di lokasi itu,
 * sama dengan daftar Stok Minimum, agar angka widget dan daftar tidak berbeda.
 */
final class BelowMinimumSummary
{
    public const LIMIT = 10;

    public function __construct(private readonly DataScope $scope) {}

    /** @return array{total: int, items: list<array{product_id: int, sku: string, name: string, location_id: int, location: string, qty_on_hand: string, min_qty_base: string}>} */
    public function for(User $user): array
    {
        $balance = DB::table('stock_batches')->selectRaw('product_id, stock_location_id, SUM(qty_on_hand) AS qty_on_hand')
            ->groupBy('product_id', 'stock_location_id');

        $query = ProductStockLevel::query()
            ->join('products', 'products.id', '=', 'product_stock_levels.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'product_stock_levels.stock_location_id')
            ->leftJoinSub($balance, 'bal', fn ($j) => $j->on('bal.product_id', '=', 'product_stock_levels.product_id')
                ->on('bal.stock_location_id', '=', 'product_stock_levels.stock_location_id'))
            ->whereNull('products.deleted_at')
            ->where('product_stock_levels.min_qty_base', '>', 0)
            ->whereRaw('COALESCE(bal.qty_on_hand, 0) < product_stock_levels.min_qty_base');
        $this->scope->forStock($query, $user, 'product_stock_levels.stock_location_id');

        $total = (clone $query)->count();
        $items = array_values($query
            ->select('product_stock_levels.product_id', 'product_stock_levels.stock_location_id', 'product_stock_levels.min_qty_base',
                'products.sku', 'products.name', 'stock_locations.name as location_name')
            ->selectRaw('COALESCE(bal.qty_on_hand, 0) AS qty_on_hand')
            ->orderByRaw('COALESCE(bal.qty_on_hand, 0) / product_stock_levels.min_qty_base')
            ->orderBy('products.name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (ProductStockLevel $l) => [
                'product_id' => $l->product_id,
                'sku' => (string) $l->getAttribute('sku'),
                'name' => (string) $l->getAttribute('name'),
                'location_id' => $l->stock_location_id,
                'location' => (string) $l->getAttribute('location_name'),
                'qty_on_hand' => (string) $l->getAttribute('qty_on_hand'),
                'min_qty_base' => (string) $l->min_qty_base,
            ])->all());

        return ['total' => $total, 'items' => $items];
    }
}
