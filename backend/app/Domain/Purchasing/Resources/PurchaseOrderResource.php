<?php

namespace App\Domain\Purchasing\Resources;

use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Support\Time\DisplayTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Props detail/form PO (15 §7.1 dokumen bergaris). Uang & qty string desimal (ADR-101); waktu
 * ISO-8601 UTC (ditampilkan di zona kantor oleh frontend), tanggal dokumen tanggal sipil.
 *
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PurchaseOrder $po */
        $po = $this->resource;
        $po->loadMissing(['supplier:id,code,name,payment_term_days', 'stockLocation:id,code,name', 'creator:id,name', 'lines.product:id,sku,name,base_unit_id', 'lines.product.baseUnit:id,name', 'lines.productUnit.unit:id,name']);

        return [
            'id' => $po->id,
            'number' => $po->number,
            'supplier' => ['id' => $po->supplier->id, 'code' => $po->supplier->code, 'name' => $po->supplier->name],
            'stock_location' => ['id' => $po->stockLocation->id, 'code' => $po->stockLocation->code, 'name' => $po->stockLocation->name],
            'order_date' => $po->order_date->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'payment_term_days' => $po->payment_term_days,
            'subtotal' => $po->subtotal,
            'discount_total' => $po->discount_total,
            'tax_total' => $po->tax_total,
            'grand_total' => $po->grand_total,
            'status' => $po->status->value,
            'status_label' => $po->status->label(),
            'approval_request_id' => $po->approval_request_id,
            'submitted_at' => $po->submitted_at?->toIso8601String(),
            'approved_at' => $po->approved_at?->toIso8601String(),
            'closed_at' => $po->closed_at?->toIso8601String(),
            'close_reason' => $po->close_reason,
            'note' => $po->note,
            'created_by' => $po->creator?->name,
            'created_at' => $po->created_at?->toIso8601String(),
            'overdue' => $po->status->isOpen() && $po->expected_date !== null
                && $po->expected_date->toDateString() < DisplayTime::toLocal(now())->toDateString(),
            'lines' => $po->lines->map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'product_id' => $l->product_id,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'product_unit_id' => $l->product_unit_id,
                'unit' => $l->productUnit->unitLabel(),
                'qty' => $l->qty,
                'qty_base' => $l->qty_base,
                'unit_price' => $l->unit_price,
                'discount_pct' => $l->discount_pct,
                'line_total' => $l->line_total,
                'qty_received_base' => $l->qty_received_base,
                'base_unit' => $l->product->baseUnit->name,
                'note' => $l->note,
            ])->values(),
        ];
    }
}
