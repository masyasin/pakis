<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Status penerimaan PO dari `qty_received_base` (BR-160): semua baris terpenuhi → `received`;
 * sebagian → `partially_received`. Dipanggil penerimaan barang (T-080) di dalam transaksinya.
 * PO di luar `approved`/`partially_received` tidak disentuh.
 */
final class RecalculatePurchaseOrder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! $locked->status->isOpen()) {
                return $locked;
            }

            $lines = PurchaseOrderLine::query()->where('purchase_order_id', $locked->id)->get();
            $any = $lines->contains(fn (PurchaseOrderLine $l) => BigDecimal::of($l->qty_received_base)->isPositive());
            $all = $lines->isNotEmpty() && $lines->every(fn (PurchaseOrderLine $l) => BigDecimal::of($l->qty_received_base)->isGreaterThanOrEqualTo($l->qty_base));
            $to = $all ? PurchaseOrderStatus::Received : ($any ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Approved);

            if ($to !== $locked->status) {
                $before = $locked->audited();
                $locked->forceFill(['status' => $to])->save();
                $this->audit->record('purchase_order.receipt_status', $locked, $before, $locked->audited());
            }

            return $locked;
        });
    }
}
