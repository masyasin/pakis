<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;

/**
 * Saldo nyata dari buku stok (T-077): lokasi/rak bersaldo bila ada batch dengan `qty_on_hand > 0`,
 * berapa pun statusnya (stok karantina/kedaluwarsa juga harus tetap terjangkau dokumen).
 */
final class LedgerStockBalanceProbe implements StockBalanceProbe
{
    public function locationHasStock(StockLocation $location): bool
    {
        return StockBatch::query()->where('stock_location_id', $location->id)->where('qty_on_hand', '>', 0)->exists();
    }

    public function binHasStock(StockBin $bin): bool
    {
        return StockBatch::query()->where('stock_bin_id', $bin->id)->where('qty_on_hand', '>', 0)->exists();
    }
}
