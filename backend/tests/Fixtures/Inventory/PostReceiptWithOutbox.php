<?php

namespace Tests\Fixtures\Inventory;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\LedgerResult;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;

/**
 * FIXTURE pola wajib Action pemancar stok (15 §11.4, T-080 dst.): satu `StockLedger::transaction`
 * berisi PeriodGuard → StockLedger → AccountingOutbox dengan tanggal bisnis yang sama. Dipakai
 * LedgerOutboxIntegrationTest untuk membuktikan semuanya jatuh/berhasil bersama.
 */
final class PostReceiptWithOutbox
{
    public function __construct(private readonly StockLedger $ledger) {}

    /** @param  bool  $breakPayload  kirim payload rusak (peran tak dikenal) untuk menguji rollback bila outbox gagal */
    public function handle(StockLocation $location, Product $product, string $qty, string $cost, string $businessDate, int $receiptId, int $supplierId, bool $breakPayload = false): LedgerResult
    {
        return StockLedger::transaction(function () use ($location, $product, $qty, $cost, $businessDate, $receiptId, $supplierId, $breakPayload) {
            PeriodGuard::assertOpen($businessDate, 'business_date');

            $result = $this->ledger->receive($location, $product, new BatchKey('B-INT', '2027-12-31'), Qty::of($qty), UnitCost::of($cost),
                new Source('goods_receipt', $receiptId, 1), $businessDate);

            $value = (string) $result->value();
            $dims = ['stock_location_id' => $location->id, 'product_id' => $product->id];
            AccountingOutbox::record(EventCode::InventoryGoodsReceived, new EventSource('goods_receipt', $receiptId), $businessDate, [
                'lines' => [
                    ['role' => 'inventory', 'debit' => $value, ...$dims],
                    ['role' => $breakPayload ? 'peran_palsu' : 'goods_received_not_invoiced', 'credit' => $value, ...$dims],
                ],
                'refs' => ['supplier_id' => $supplierId],
            ]);

            return $result;
        });
    }
}
