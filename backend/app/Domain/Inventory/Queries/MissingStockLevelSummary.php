<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Inventory\Listings\StockLevelListing;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;

/**
 * Kartu dasbor "Produk tanpa stok minimum" (T-087A, PA-73): per gudang/toko aktif dalam scope Stok,
 * jumlah produk aktif yang belum punya baris `product_stock_levels` di lokasi itu. Basis sama dengan
 * daftar Stok Minimum (`StockLevelListing::locations()` + produk aktif) dengan filter `unset=1`, jadi
 * angka per lokasi = jumlah baris halaman yang dibuka dari kartu. Lokasi tanpa kekurangan tidak tampil.
 */
final class MissingStockLevelSummary
{
    public const LIMIT = 10;

    /** @return array{total: int, items: list<array{location_id: int, location: string, missing: int}>} */
    public function for(User $user): array
    {
        $missing = fn ($q) => $q->selectRaw('COUNT(*)')->from('products')
            ->where('products.status', ProductStatus::Active->value)
            ->whereNull('products.deleted_at')
            ->whereNotExists(fn ($l) => $l->selectRaw('1')->from('product_stock_levels')
                ->whereColumn('product_stock_levels.product_id', 'products.id')
                ->whereColumn('product_stock_levels.stock_location_id', 'stock_locations.id'));

        $rows = StockLevelListing::locations($user)
            ->select(['stock_locations.id', 'stock_locations.name'])
            ->selectSub($missing, 'missing')
            ->get()
            ->map(fn (StockLocation $l) => ['location_id' => $l->id, 'location' => $l->name, 'missing' => (int) $l->getAttribute('missing')])
            ->filter(fn (array $r) => $r['missing'] > 0)
            ->sortBy([['missing', 'desc'], ['location', 'asc']])
            ->values()
            ->all();

        return [
            'total' => array_sum(array_column($rows, 'missing')),
            'items' => array_slice($rows, 0, self::LIMIT),
        ];
    }
}
