<?php

namespace Tests\Fixtures\Accounting;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use Illuminate\Support\Facades\DB;

/**
 * FIXTURE PATUH: PeriodGuard::assertOpen() dengan tanggal bisnis yang sama, sebelum mutasi & event,
 * di transaksi yang sama (pola wajib Action pemancar, 15 §11.4).
 */
final class CompliantOutboxAction
{
    public function handle(int $documentId, string $businessDate): void
    {
        DB::transaction(function () use ($documentId, $businessDate) {
            PeriodGuard::assertOpen($businessDate);
            // ... tulis mutasi stok lewat StockLedger ...
            AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', $documentId), $businessDate, ['lines' => []]);
        });
    }
}
