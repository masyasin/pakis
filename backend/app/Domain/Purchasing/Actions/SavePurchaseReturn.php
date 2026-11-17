<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah draf retur ke prinsipal (FR-A26, BR-155, BR-159). Supplier aktif, alasan dari grup
 * `purchase_return_reason`, `goods_receipt_id` opsional (penerimaan `posted` dari supplier & lokasi yang
 * sama). Baris = batch di lokasi itu (termasuk karantina/kedaluwarsa, bukan `disposed`), qty dasar ≤
 * tersedia (`INSUFFICIENT_STOCK`), produk bernomor seri hanya qty bulat. Biaya baris = biaya beli batch
 * (`receipt_unit_cost`), cadangan HPP rata-rata saat ini; `total_cost` dihitung server.
 */
final class SavePurchaseReturn
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data  sudah divalidasi PurchaseReturnRequest
     * @param  list<array{stock_batch_id: int, qty_base: string}>  $lines
     */
    public function handle(?PurchaseReturn $return, array $data, array $lines, User $actor): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $data, $lines, $actor) {
            $supplier = Supplier::query()->find((int) $data['supplier_id']);
            if ($supplier === null || ! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier tidak aktif.']);
            }
            $locationId = (int) $data['stock_location_id'];
            $reasonOk = ReferenceItem::query()->forGroup(ReferenceGroup::PurchaseReturnReason)->whereKey((int) $data['reason_id'])->exists();
            if (! $reasonOk) {
                throw ValidationException::withMessages(['reason_id' => 'Pilih alasan retur.']);
            }
            $receiptId = ($data['goods_receipt_id'] ?? null) === null ? null : (int) $data['goods_receipt_id'];
            if ($receiptId !== null) {
                $ok = GoodsReceipt::query()->whereKey($receiptId)->where('status', GoodsReceiptStatus::Posted->value)
                    ->where('supplier_id', $supplier->id)->where('stock_location_id', $locationId)->exists();
                if (! $ok) {
                    throw ValidationException::withMessages(['goods_receipt_id' => 'Penerimaan harus sudah diposting, dari supplier dan lokasi yang sama.']);
                }
            }

            [$built, $total] = $this->buildLines($lines, $locationId);
            $values = [
                'supplier_id' => $supplier->id,
                'stock_location_id' => $locationId,
                'goods_receipt_id' => $receiptId,
                'reason_id' => (int) $data['reason_id'],
                'note' => ($data['note'] ?? '') === '' ? null : $data['note'],
                'total_cost' => $total,
            ];

            if ($return === null) {
                $saved = new PurchaseReturn;
                $saved->forceFill([
                    ...$values,
                    'number' => $this->numbers->next('purchase_return', isTaken: fn (string $n) => PurchaseReturn::query()->where('number', $n)->exists()),
                    'status' => PurchaseReturnStatus::Draft,
                    'created_by' => $actor->id, 'updated_by' => $actor->id,
                ])->save();
                $before = null;
            } else {
                $saved = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
                if ($saved->status !== PurchaseReturnStatus::Draft) {
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $saved->status->value], 'Hanya retur draf yang bisa diubah.');
                }
                $before = $saved->audited();
                $saved->forceFill([...$values, 'updated_by' => $actor->id])->save();
                PurchaseReturnLine::query()->where('purchase_return_id', $saved->id)->delete();
            }

            foreach ($built as $row) {
                (new PurchaseReturnLine)->forceFill([...$row, 'purchase_return_id' => $saved->id])->save();
            }

            $this->audit->record($before === null ? 'purchase_return.created' : 'purchase_return.updated', $saved, $before, [
                ...$saved->audited(), 'lines' => count($built),
            ]);

            return $saved;
        });
    }

    /**
     * @param  list<array{stock_batch_id: int, qty_base: string}>  $lines
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function buildLines(array $lines, int $locationId): array
    {
        $ids = array_column($lines, 'stock_batch_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['lines' => 'Satu batch hanya boleh muncul sekali.']);
        }
        $batches = StockBatch::query()->with('product:id,sku,tracks_serial')->whereIn('id', $ids)->get()->keyBy('id');

        $built = [];
        $total = BigDecimal::zero();
        foreach ($lines as $i => $line) {
            $batch = $batches->get($line['stock_batch_id']);
            if ($batch === null || $batch->stock_location_id !== $locationId) {
                throw ValidationException::withMessages(["lines.{$i}.stock_batch_id" => 'Batch tidak ditemukan di lokasi ini.']);
            }
            if ($batch->status === StockBatchStatus::Disposed) {
                throw ValidationException::withMessages(["lines.{$i}.stock_batch_id" => 'Batch sudah dimusnahkan.']);
            }
            $qty = Qty::of($line['qty_base']);
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["lines.{$i}.qty_base" => 'Jumlah harus lebih dari 0.']);
            }
            if ($batch->product->tracks_serial && ! $qty->isInteger()) {
                throw ValidationException::withMessages(["lines.{$i}.qty_base" => 'Produk bernomor seri hanya bisa diretur dalam jumlah bulat.']);
            }
            if ($batch->available()->compareTo($qty) < 0) {
                throw new InsufficientStock($batch->id, $batch->available(), $qty);
            }

            // HPP dibaca lewat model ProductCost (CostCalculator khusus Ledger, StockLedgerArchTest); tanpa baris = 0.
            $unitCost = $batch->receiptUnitCost() ?? (ProductCost::query()->find($batch->product_id)?->avgCost() ?? UnitCost::zero());
            $total = $total->plus($qty->toBigDecimal()->multipliedBy((string) $unitCost));
            $built[] = [
                'stock_batch_id' => $batch->id,
                'product_id' => $batch->product_id,
                'qty_base' => (string) $qty,
                'unit_cost' => (string) $unitCost,
                'reservation_id' => null,
            ];
        }

        return [$built, (string) $total->toScale(2, RoundingMode::HalfUp)];
    }
}
