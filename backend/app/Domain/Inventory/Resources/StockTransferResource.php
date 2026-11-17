<?php

namespace App\Domain\Inventory\Resources;

use App\Domain\Inventory\Approvables\TransferVarianceApproval;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Props detail/form mutasi (15 §7.1 dokumen bergaris). Qty string desimal satuan dasar (ADR-102);
 * waktu ISO-8601 UTC (frontend menampilkan zona kantor). Nilai selisih (HPP) hanya untuk pemegang
 * `stock.view_cost` (15 §6.3: nilai stok = biaya); pengguna lain menerima null.
 *
 * @mixin StockTransfer
 */
class StockTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockTransfer $t */
        $t = $this->resource;
        $t->loadMissing([
            'fromLocation:id,code,name,type', 'toLocation:id,code,name,type', 'vehicle:id,plate_no,name',
            'shipper:id,name', 'receiver:id,name', 'creator:id,name',
            'lines.product:id,sku,name,base_unit_id', 'lines.product.baseUnit:id,name',
            'lines.sourceBatch:id,batch_no,expiry_date', 'lines.destBatch:id,batch_no,expiry_date', 'lines.destBin:id,code,name',
        ]);
        $canCost = $request->user()?->can('stock.view_cost') ?? false;

        return [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'from_location' => self::location($t->fromLocation),
            'to_location' => self::location($t->toLocation),
            'vehicle' => $t->vehicle === null ? null : ['id' => $t->vehicle->id, 'plate_no' => $t->vehicle->plate_no, 'name' => $t->vehicle->name],
            'note' => $t->note,
            'created_by' => $t->creator?->name,
            'created_at' => $t->created_at?->toIso8601String(),
            'shipped_by' => $t->shipper?->name,
            'shipped_by_id' => $t->shipped_by,
            'shipped_at' => $t->shipped_at?->toIso8601String(),
            'received_by' => $t->receiver?->name,
            'received_at' => $t->received_at?->toIso8601String(),
            'approval_request_id' => $t->approval_request_id,
            'overdue' => $t->isOverdue(),
            // Hitung ulang terbuka = selisih ditolak/ditarik (PA-66).
            'recount_open' => $t->acceptsReceipt() && $t->status === StockTransferStatus::ReceivedWithVariance,
            'variance_value' => $canCost && $t->varianceLines()->isNotEmpty()
                ? (string) TransferVarianceApproval::query()->findOrFail($t->id)->varianceValue()
                : null,
            'lines' => $t->lines->map(fn (StockTransferLine $l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'unit' => $l->product->baseUnit->name,
                'source_batch' => self::batch($l->sourceBatch),
                'dest_batch' => self::batch($l->destBatch),
                'qty_requested_base' => $l->qty_requested_base,
                'qty_shipped_base' => $l->qty_shipped_base,
                'qty_received_base' => $l->qty_received_base,
                'variance_base' => $l->qty_shipped_base === null || $l->qty_received_base === null ? null : (string) $l->variance(),
                'variance_reason' => $l->variance_reason,
                'dest_bin' => $l->destBin === null ? null : ['id' => $l->destBin->id, 'code' => $l->destBin->code, 'name' => $l->destBin->name],
                'fefo_override' => $l->fefo_override,
            ])->values(),
        ];
    }

    /** @return array{id: int, code: string, name: string, type: string} */
    private static function location(StockLocation $l): array
    {
        return ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'type' => $l->type->value];
    }

    /** @return array{id: int, batch_no: string, expiry_date: string|null}|null */
    private static function batch(?StockBatch $b): ?array
    {
        return $b === null ? null : ['id' => $b->id, 'batch_no' => $b->batch_no, 'expiry_date' => $b->expiry_date?->toDateString()];
    }
}
