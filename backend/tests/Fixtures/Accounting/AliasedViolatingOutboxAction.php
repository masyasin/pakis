<?php

namespace Tests\Fixtures\Accounting;

use App\Domain\Accounting\Outbox\AccountingOutbox as Outbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;

/**
 * FIXTURE PELANGGAR: alias impor + pemakaian instance, dan PeriodGuard dirujuk tanpa assertOpen().
 */
final class AliasedViolatingOutboxAction
{
    public function handle(int $documentId): void
    {
        if (! PeriodGuard::isOpen('2026-11-17')) {
            return;
        }
        $outbox = app(Outbox::class);
        $outbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', $documentId), '2026-11-17', ['lines' => []]);
        $text = "PeriodGuard::assertOpen('{$documentId}')"; // string, bukan panggilan
        unset($text);
    }
}
