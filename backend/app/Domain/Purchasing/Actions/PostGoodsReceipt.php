<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Approvables\ShortShelfLifeApproval;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Support\ReceiptChecks;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posting penerimaan (`checked → posted`, 15 §6.7 "Posting penerimaan", BR-158(6), BR-159, SEC-104,
 * §11.4) dalam satu `StockLedger::transaction` (PA-62): kunci GR & PO `FOR UPDATE`, status wajib
 * `checked` (klik ganda → satu posting + satu `409 STATE_CONFLICT`), pemosting ≠ pemeriksa bila
 * `purchasing.receipt_two_person`, cek ulang PO terbuka + over-receipt + jumlah seri.
 *
 * Ada baris `short_shelf_life` → `pending_approval` + permintaan approval
 * `goods_receipt_short_shelf_life` (BR-103); posting inti dijalankan saat disetujui (`postApproved`).
 * Posting inti: `PeriodGuard` dengan tanggal bisnis penerimaan, `StockLedger::receive` per baris
 * (baris `registration_mismatch` ke rak karantina `Q-01` → batch `quarantine`, BR-158(4)), isi
 * `stock_batch_id` baris & nomor seri, naikkan `qty_received_base` PO + status PO, `supplier_products.
 * last_price`, lalu satu event `inventory.goods_received` (D `inventory` = K GRNI per baris, dims
 * lokasi+produk, `refs.supplier_id`).
 */
final class PostGoodsReceipt
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
        private readonly RecalculatePurchaseOrder $recalculate,
    ) {}

    public function handle(GoodsReceipt $receipt, User $actor): GoodsReceipt
    {
        return StockLedger::transaction(function () use ($receipt, $actor) {
            $locked = ShortShelfLifeApproval::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status !== GoodsReceiptStatus::Checked) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value],
                    'Penerimaan hanya bisa diposting setelah diperiksa dan belum diposting.');
            }
            if ((bool) setting('purchasing.receipt_two_person') && $locked->checked_by === $actor->id) {
                throw new ApiError(ErrorCode::RECEIPT_SAME_PERSON, ['form' => ErrorCode::RECEIPT_SAME_PERSON->message()]);
            }

            $order = $this->lockOrder($locked);
            $lines = $this->lines($locked);
            ReceiptChecks::assertPurchaseOrderOpen($order);
            ReceiptChecks::assertWithinTolerance($locked, $lines);
            ReceiptChecks::assertSerialCounts($lines);

            if ($lines->contains(fn (GoodsReceiptLine $l) => $l->line_status === ReceiptLineStatus::ShortShelfLife)) {
                $before = $locked->audited();
                $locked->forceFill(['status' => GoodsReceiptStatus::PendingApproval, 'posted_by' => $actor->id, 'updated_by' => $actor->id])->save();
                $this->audit->record('goods_receipt.submitted', $locked, $before, $locked->audited());

                $request = $this->engine->request($locked, $actor);
                if ($request !== null) {
                    $locked->forceFill(['approval_request_id' => $request->id])->save();
                }

                return $locked->refresh();
            }

            $this->post($locked, $order, $lines, $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Dipanggil `ShortShelfLifeApproval::onApproved` di dalam transaksi mesin approval (savepoint
     * `StockLedger::transaction`). Pemosting = pengaju yang dicatat di `posted_by`.
     */
    public function postApproved(GoodsReceipt $locked): void
    {
        StockLedger::transaction(function () use ($locked) {
            $order = $this->lockOrder($locked);
            $lines = $this->lines($locked);
            ReceiptChecks::assertPurchaseOrderOpen($order);
            ReceiptChecks::assertWithinTolerance($locked, $lines);
            ReceiptChecks::assertSerialCounts($lines);

            $this->post($locked, $order, $lines, (int) ($locked->posted_by ?? $locked->created_by));
        });
    }

    /** @param  Collection<int, GoodsReceiptLine>  $lines */
    private function post(GoodsReceipt $locked, ?PurchaseOrder $order, Collection $lines, int $postedBy): void
    {
        $date = $locked->businessDate();
        // ADR-115: tanggal bisnis yang sama dengan mutasi & event di bawah; kunci → PERIOD_LOCKED tanpa mutasi.
        PeriodGuard::assertOpen($date, 'received_at');

        $location = StockLocation::query()->withTrashed()->findOrFail($locked->stock_location_id);
        $quarantineBin = null;
        $journal = [];
        foreach ($lines as $line) {
            $qty = Qty::of($line->qty_received_base);
            if (! $qty->isPositive()) {
                continue; // seluruh baris ditolak: tidak masuk stok, hanya tercatat untuk klaim (BR-158(7))
            }
            $binId = $line->stock_bin_id;
            if ($line->line_status === ReceiptLineStatus::RegistrationMismatch) {
                $quarantineBin ??= StockBin::query()->where('stock_location_id', $location->id)->where('code', StockBin::QUARANTINE_CODE)->value('id');
                $binId = $quarantineBin === null ? null : (int) $quarantineBin;
            }

            $result = $this->ledger->receive(
                $location,
                $line->product,
                new BatchKey($line->batch_no, $line->expiry_date?->toDateString(), $binId),
                $qty,
                UnitCost::of($line->unit_cost),
                new Source('goods_receipt', $locked->id, $line->id),
                $date,
                MovementType::Receipt,
                "Penerimaan {$locked->number}",
            );
            $batchId = $result->batch()->id;
            $line->forceFill(['stock_batch_id' => $batchId])->save();
            SerialNumber::query()->where('goods_receipt_line_id', $line->id)->update(['stock_batch_id' => $batchId, 'updated_at' => now()]);

            $value = (string) $result->value();
            $dims = ['stock_location_id' => $location->id, 'product_id' => $line->product_id];
            $journal[] = ['role' => 'inventory', 'debit' => $value, ...$dims];
            $journal[] = ['role' => 'goods_received_not_invoiced', 'credit' => $value, ...$dims];

            $this->touchPurchaseOrderLine($line, $qty);
            $this->touchLastPrice($locked->supplier_id, $line);
        }

        if ($order !== null) {
            $this->recalculate->handle($order);
        }
        if ($journal !== []) {
            AccountingOutbox::record(EventCode::InventoryGoodsReceived, new EventSource('goods_receipt', $locked->id), $date, [
                'lines' => $journal,
                'refs' => ['supplier_id' => $locked->supplier_id],
            ]);
        }

        $before = $locked->audited();
        $locked->forceFill(['status' => GoodsReceiptStatus::Posted, 'posted_by' => $postedBy, 'posted_at' => now(), 'updated_by' => $postedBy])->save();
        $this->audit->record('goods_receipt.posted', $locked, $before, [...$locked->audited(), 'lines' => $lines->count()]);
    }

    private function lockOrder(GoodsReceipt $locked): ?PurchaseOrder
    {
        return $locked->purchase_order_id === null ? null : PurchaseOrder::query()->lockForUpdate()->findOrFail($locked->purchase_order_id);
    }

    /** @return Collection<int, GoodsReceiptLine> */
    private function lines(GoodsReceipt $locked): Collection
    {
        return GoodsReceiptLine::query()->with(['product', 'productUnit', 'purchaseOrderLine'])
            ->where('goods_receipt_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
    }

    private function touchPurchaseOrderLine(GoodsReceiptLine $line, Qty $qty): void
    {
        if ($line->purchase_order_line_id === null) {
            return;
        }
        $poLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
        $poLine->forceFill(['qty_received_base' => (string) BigDecimal::of($poLine->qty_received_base)->plus($qty->toBigDecimal())])->save();
    }

    /**
     * Harga beli terakhir supplier (15 §4.5; model baca saja → query builder). Dengan PO: harga & satuan
     * baris PO; tanpa PO: biaya dasar × faktor satuan baris.
     */
    private function touchLastPrice(int $supplierId, GoodsReceiptLine $line): void
    {
        $poLine = $line->purchaseOrderLine;
        $price = $poLine !== null
            ? $poLine->unit_price
            : (string) Money::of(BigDecimal::of($line->unit_cost)->multipliedBy($line->productUnit->conversionFactor()));
        $unitId = $poLine->product_unit_id ?? $line->product_unit_id;

        DB::table('supplier_products')
            ->where('supplier_id', $supplierId)->where('product_id', $line->product_id)
            ->update(['last_price' => $price, 'last_price_unit_id' => $unitId, 'updated_at' => now()]);
    }
}
