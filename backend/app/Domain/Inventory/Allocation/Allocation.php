<?php

namespace App\Domain\Inventory\Allocation;

use App\Domain\Inventory\Ledger\StockLine;
use App\Support\Money\Qty;

/**
 * Hasil `FefoAllocator::allocate()` (BR-102): batch + qty satuan dasar, urut FEFO. Hanya rencana
 * baca-saja: diteruskan apa adanya ke `StockLedger::issue($allocation->lines, null, ...)` (atau
 * `reserve()` per baris) yang mengunci baris dan memvalidasi ulang, karena saldo bisa berubah sejak
 * alokasi dihitung (alokasi basi → `INSUFFICIENT_STOCK`/`BATCH_NOT_AVAILABLE` dari ledger).
 */
final readonly class Allocation
{
    /** @param  list<StockLine>  $lines */
    public function __construct(
        public array $lines,
        public Qty $total,
    ) {}

    /** @return list<int> ID batch terpakai, urut FEFO. */
    public function batchIds(): array
    {
        return array_map(fn (StockLine $line) => $line->batch->id, $this->lines);
    }
}
