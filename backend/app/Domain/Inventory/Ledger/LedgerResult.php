<?php

namespace App\Domain\Inventory\Ledger;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Money\Money;
use LogicException;

/**
 * Hasil satu panggilan StockLedger: mutasi yang tertulis (urut penulisan) dan saldo batch terbaru.
 * Nilai (`qty × unit_cost`) disediakan untuk payload outbox akuntansi pemanggil (15 §11.4).
 */
final readonly class LedgerResult
{
    /**
     * @param  list<StockMovement>  $movements
     * @param  array<int, StockBatch>  $batches  id batch => batch setelah mutasi
     */
    public function __construct(
        public array $movements,
        public array $batches,
    ) {}

    public function movement(): StockMovement
    {
        if (count($this->movements) !== 1) {
            throw new LogicException('Hasil ini memuat '.count($this->movements).' mutasi; pakai $movements.');
        }

        return $this->movements[0];
    }

    public function batch(): StockBatch
    {
        return $this->batches[$this->movement()->stock_batch_id];
    }

    /** Total nilai absolut mutasi (Σ |qty| × unit_cost), dibulatkan half-up ke rupiah. */
    public function value(): Money
    {
        $total = Money::zero()->toBigDecimal();
        foreach ($this->movements as $m) {
            $total = $total->plus($m->qtyValue()->toBigDecimal()->abs()->multipliedBy($m->unitCostValue()->toBigDecimal()));
        }

        return Money::of($total);
    }
}
