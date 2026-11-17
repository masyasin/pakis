<?php

namespace App\Domain\Inventory\Ledger;

use App\Domain\Inventory\Models\StockBatch;
use App\Support\Money\Qty;

/** Satu batch + qty satuan dasar untuk `StockLedger::issue()` banyak batch (mis. hasil FEFO). */
final readonly class StockLine
{
    public function __construct(
        public StockBatch $batch,
        public Qty $qty,
    ) {}
}
