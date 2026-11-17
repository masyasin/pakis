<?php

namespace Tests\Fixtures\Accounting;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use Illuminate\Support\Facades\DB;

/**
 * FIXTURE PELANGGAR PeriodGuardArchTest: memancarkan event tanpa PeriodGuard::assertOpen().
 * Komentar ini menyebut PeriodGuard::assertOpen() tetapi komentar tidak dihitung pemindai.
 * Tidak pernah dijalankan; hanya dibaca pemindai untuk membuktikan pelanggaran terdeteksi.
 */
final class ViolatingOutboxAction
{
    public function handle(int $documentId): void
    {
        DB::transaction(function () use ($documentId) {
            AccountingOutbox::record(EventCode::InventoryAdjusted, new EventSource('stock_opname', $documentId), '2026-11-17', ['lines' => []]);
        });
    }
}
