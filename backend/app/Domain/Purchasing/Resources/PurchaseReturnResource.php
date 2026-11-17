<?php

namespace App\Domain\Purchasing\Resources;

use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Props detail/form retur (15 §7.1 dokumen bergaris). **SEC-107:** `unit_cost`, `line_value`, dan
 * `total_cost` hanya untuk pemegang `purchase_orders.view` / `stock.view_cost` (pola
 * GoodsReceiptResource). Untuk baris produk bernomor seri yang belum dikirim, `serial_options` = nomor
 * seri `in_stock` di batch itu (dipilih di dialog Kirim, PA-68).
 *
 * @mixin PurchaseReturn
 */
class PurchaseReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PurchaseReturn $ret */
        $ret = $this->resource;
        $ret->loadMissing([
            'supplier:id,code,name', 'stockLocation:id,code,name', 'reason:id,name', 'goodsReceipt:id,number', 'creator:id,name',
            'lines.product:id,sku,name,tracks_serial,base_unit_id', 'lines.product.baseUnit:id,name',
            'lines.batch:id,batch_no,expiry_date,status,stock_bin_id,qty_on_hand,qty_reserved', 'lines.batch.bin:id,code', 'lines.reservation:id,status',
        ]);
        $user = $request->user();
        $showCost = GoodsReceiptResource::canSeeCost($user instanceof User ? $user : null);
        $shippable = $ret->status->value === 'approved';

        return [
            'id' => $ret->id,
            'number' => $ret->number,
            'supplier' => ['id' => $ret->supplier->id, 'code' => $ret->supplier->code, 'name' => $ret->supplier->name],
            'stock_location' => ['id' => $ret->stockLocation->id, 'code' => $ret->stockLocation->code, 'name' => $ret->stockLocation->name],
            'goods_receipt' => $ret->goodsReceipt === null ? null : ['id' => $ret->goodsReceipt->id, 'number' => $ret->goodsReceipt->number],
            'reason' => ['id' => $ret->reason->id, 'name' => $ret->reason->name],
            'status' => $ret->status->value,
            'status_label' => $ret->status->label(),
            'approval_request_id' => $ret->approval_request_id,
            'shipped_at' => $ret->shipped_at?->toIso8601String(),
            'closed_at' => $ret->closed_at?->toIso8601String(),
            'note' => $ret->note,
            'created_by' => $ret->creator?->name,
            'created_at' => $ret->created_at?->toIso8601String(),
            'show_cost' => $showCost,
            'total_cost' => $showCost ? $ret->total_cost : null,
            'lines' => $ret->lines->map(function (PurchaseReturnLine $l) use ($showCost, $shippable) {
                $row = [
                    'id' => $l->id,
                    'stock_batch_id' => $l->stock_batch_id,
                    'product_id' => $l->product_id,
                    'sku' => $l->product->sku,
                    'name' => $l->product->name,
                    'tracks_serial' => $l->product->tracks_serial,
                    'base_unit' => $l->product->baseUnit->name,
                    'batch_no' => $l->batch->batch_no,
                    'expiry_date' => $l->batch->expiry_date?->toDateString(),
                    'batch_status' => $l->batch->status->value,
                    'bin' => $l->batch->bin?->code,
                    'qty_base' => $l->qty_base,
                    'available_base' => (string) $l->batch->available(),
                    'reservation_status' => $l->reservation?->status->value,
                    'serial_options' => $shippable && $l->product->tracks_serial
                        ? SerialNumber::query()->where('product_id', $l->product_id)->where('stock_batch_id', $l->stock_batch_id)
                            ->where('status', SerialStatus::InStock->value)->orderBy('serial_no')->limit(500)->pluck('serial_no')->all()
                        : [],
                ];
                if ($showCost) {
                    $row['unit_cost'] = $l->unit_cost;
                    $row['line_value'] = (string) BigDecimal::of($l->qty_base)->multipliedBy($l->unit_cost)->toScale(2, RoundingMode::HalfUp);
                }

                return $row;
            })->values(),
        ];
    }
}
