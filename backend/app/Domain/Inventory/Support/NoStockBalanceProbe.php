<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;

/** Sebelum buku stok ada (T-077) tidak ada saldo apa pun. T-077 mengganti binding ini. */
final class NoStockBalanceProbe implements StockBalanceProbe
{
    public function locationHasStock(StockLocation $location): bool
    {
        return false;
    }

    public function binHasStock(StockBin $bin): bool
    {
        return false;
    }
}
