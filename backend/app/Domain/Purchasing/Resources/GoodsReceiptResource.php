<?php

namespace App\Domain\Purchasing\Resources;

use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Props detail/form penerimaan (15 §7.1 dokumen bergaris). Qty string desimal (ADR-101); `received_at`
 * ISO-8601 UTC + `received_at_local` (`Y-m-d\TH:i` zona kantor) untuk input form. **SEC-107:**
 * `unit_cost`, `line_value`, dan `total_value` hanya dikirim bila pengguna memegang
 * `purchase_orders.view` atau `stock.view_cost`; staf gudang tidak melihat harga beli.
 *
 * @mixin GoodsReceipt
 */
class GoodsReceiptResource extends JsonResource
{
    public static function canSeeCost(?User $user): bool
    {
        return $user !== null && ($user->can('purchase_orders.view') || $user->can('stock.view_cost'));
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var GoodsReceipt $gr */
        $gr = $this->resource;
        $gr->loadMissing([
            'supplier:id,code,name', 'stockLocation:id,code,name', 'purchaseOrder:id,number,status', 'creator:id,name', 'checker:id,name', 'poster:id,name',
            'lines.product:id,sku,name,tracks_batch,tracks_expiry,tracks_serial,requires_registration,min_shelf_life_months,base_unit_id',
            'lines.product.baseUnit:id,name', 'lines.productUnit.unit:id,name', 'lines.bin:id,code', 'lines.rejectReason:id,name', 'lines.batch:id,status',
            'lines.serials:id,goods_receipt_line_id,serial_no,input_method',
        ]);
        $user = $request->user();
        $showCost = self::canSeeCost($user instanceof User ? $user : null);
        $total = BigDecimal::zero();

        $lines = $gr->lines->map(function (GoodsReceiptLine $l) use ($showCost, &$total) {
            $value = BigDecimal::of($l->qty_received_base)->multipliedBy($l->unit_cost)->toScale(2, RoundingMode::HalfUp);
            $total = $total->plus($value);
            $row = [
                'id' => $l->id,
                'purchase_order_line_id' => $l->purchase_order_line_id,
                'product_id' => $l->product_id,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'tracks_batch' => $l->product->tracks_batch,
                'tracks_expiry' => $l->product->tracks_expiry,
                'tracks_serial' => $l->product->tracks_serial,
                'requires_registration' => $l->product->requires_registration,
                'product_unit_id' => $l->product_unit_id,
                'unit' => $l->productUnit->unitLabel(),
                'base_unit' => $l->product->baseUnit->name,
                'factor' => $l->productUnit->conversionFactor(),
                'qty_received' => $l->qty_received,
                'qty_received_base' => $l->qty_received_base,
                'qty_rejected_base' => $l->qty_rejected_base,
                'reject_reason_id' => $l->reject_reason_id,
                'reject_reason' => $l->rejectReason?->name,
                'batch_no' => $l->batch_no,
                'expiry_date' => $l->expiry_date?->toDateString(),
                'registration_no_seen' => $l->registration_no_seen,
                'stock_bin_id' => $l->stock_bin_id,
                'bin' => $l->bin?->code,
                'shelf_life_months' => $l->shelf_life_months,
                'line_status' => $l->line_status->value,
                'line_status_label' => $l->line_status->label(),
                'stock_batch_id' => $l->stock_batch_id,
                'batch_status' => $l->batch?->status->value,
                'serials' => $l->serials->map(fn (SerialNumber $s) => ['id' => $s->id, 'serial_no' => $s->serial_no, 'input_method' => $s->input_method->value])->values(),
            ];
            if ($showCost) {
                $row['unit_cost'] = $l->unit_cost;
                $row['line_value'] = (string) $value;
            }

            return $row;
        })->values();

        return [
            'id' => $gr->id,
            'number' => $gr->number,
            'purchase_order' => $gr->purchaseOrder === null ? null : ['id' => $gr->purchaseOrder->id, 'number' => $gr->purchaseOrder->number, 'status' => $gr->purchaseOrder->status->value],
            'supplier' => ['id' => $gr->supplier->id, 'code' => $gr->supplier->code, 'name' => $gr->supplier->name],
            'stock_location' => ['id' => $gr->stockLocation->id, 'code' => $gr->stockLocation->code, 'name' => $gr->stockLocation->name],
            'received_at' => $gr->received_at->toIso8601String(),
            'received_at_local' => DisplayTime::toLocal($gr->received_at)->format('Y-m-d\TH:i'),
            'business_date' => $gr->businessDate(),
            'supplier_delivery_no' => $gr->supplier_delivery_no,
            'vehicle_plate' => $gr->vehicle_plate,
            'status' => $gr->status->value,
            'status_label' => $gr->status->label(),
            'approval_request_id' => $gr->approval_request_id,
            'checked_by' => $gr->checker?->name,
            'checked_at' => $gr->checked_at?->toIso8601String(),
            'posted_by' => $gr->poster?->name,
            'posted_at' => $gr->posted_at?->toIso8601String(),
            'note' => $gr->note,
            'created_by' => $gr->creator?->name,
            'created_at' => $gr->created_at?->toIso8601String(),
            'show_cost' => $showCost,
            'total_value' => $showCost ? (string) $total : null,
            'lines' => $lines,
        ];
    }
}
