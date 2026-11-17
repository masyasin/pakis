<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Allocation\FefoAllocator;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Numbering\NumberGenerator;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah mutasi draf (FR-A27, BR-155, BR-156, BR-161). Baris = produk + qty diminta (satuan
 * dasar) + batch opsional. Batch dipilih → direservasi (`inventory.reservation_ttl_hours`) agar
 * tidak terjual sebelum dikirim; tanpa batch → FEFO otomatis saat kirim. `fefo_override` dihitung
 * server (batch pilihan ≠ kandidat FEFO pertama), tidak dipercaya dari klien.
 *
 * Ubah draf: semua reservasi lama dilepas dan baris diganti utuh, lalu direservasi ulang, dalam
 * satu `StockLedger::transaction` (PA-62). Nomor dari `NumberGenerator('stock_transfer')`.
 */
final class SaveStockTransfer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
    ) {}

    /**
     * @param  array{from_stock_location_id: int, to_stock_location_id: int, vehicle_id: int|null, note: string|null}  $data
     * @param  list<array{product_id: int, qty_base: string, source_batch_id: int|null}>  $lines
     */
    public function handle(?StockTransfer $transfer, array $data, array $lines, int $userId): StockTransfer
    {
        return StockLedger::transaction(function () use ($transfer, $data, $lines, $userId) {
            $values = [
                'from_stock_location_id' => $data['from_stock_location_id'],
                'to_stock_location_id' => $data['to_stock_location_id'],
                'vehicle_id' => $data['vehicle_id'],
                'note' => $data['note'],
            ];

            if ($transfer === null) {
                $saved = new StockTransfer;
                $saved->forceFill([
                    ...$values,
                    'number' => $this->numbers->next(StockTransfer::SOURCE_TYPE, isTaken: fn (string $n) => StockTransfer::query()->where('number', $n)->exists()),
                    'status' => StockTransferStatus::Draft,
                    'created_by' => $userId, 'updated_by' => $userId,
                ])->save();
                $before = null;
            } else {
                $saved = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
                if ($saved->status !== StockTransferStatus::Draft) {
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $saved->status->value], 'Mutasi yang sudah dikirim tidak bisa diubah.');
                }
                $before = $saved->audited();
                self::releaseReservations($this->ledger, $saved);
                StockTransferLine::query()->where('stock_transfer_id', $saved->id)->delete();
                $saved->forceFill([...$values, 'updated_by' => $userId])->save();
            }

            foreach ($lines as $i => $row) {
                $this->addLine($saved, $row, $i);
            }

            $this->audit->record($before === null ? 'stock_transfer.created' : 'stock_transfer.updated', $saved, $before, [
                ...$saved->audited(), 'lines' => count($lines),
            ]);

            return $saved;
        });
    }

    /** Lepas semua reservasi aktif milik mutasi (ubah draf, batal, dan sebelum kirim). */
    public static function releaseReservations(StockLedger $ledger, StockTransfer $transfer): void
    {
        StockReservation::query()
            ->where('source_type', StockTransfer::SOURCE_TYPE)->where('source_id', $transfer->id)
            ->where('status', ReservationStatus::Active->value)->orderBy('id')->get()
            ->each(fn (StockReservation $r) => $ledger->release($r));
    }

    /** @param  array{product_id: int, qty_base: string, source_batch_id: int|null}  $row */
    private function addLine(StockTransfer $transfer, array $row, int $i): void
    {
        $product = Product::query()->find($row['product_id']);
        if ($product === null || $product->status !== ProductStatus::Active) {
            throw ValidationException::withMessages(["lines.{$i}.product_id" => 'Produk tidak ditemukan atau tidak aktif.']);
        }
        $qty = Qty::of($row['qty_base']);

        $batch = null;
        $override = false;
        if ($row['source_batch_id'] !== null) {
            $batch = StockBatch::query()->find($row['source_batch_id']);
            if ($batch === null || $batch->product_id !== $product->id || $batch->stock_location_id !== $transfer->from_stock_location_id) {
                throw ValidationException::withMessages(["lines.{$i}.source_batch_id" => 'Batch tidak ada di lokasi asal untuk produk ini.']);
            }
            if ($batch->status !== StockBatchStatus::Available) {
                throw ValidationException::withMessages(["lines.{$i}.source_batch_id" => 'Batch ini tidak tersedia (karantina/kedaluwarsa).']);
            }
            // BR-156: dicatat bila batch pilihan bukan yang akan dikeluarkan FEFO lebih dulu.
            $first = FefoAllocator::candidates($product->id, $transfer->from_stock_location_id)->value('stock_batches.id');
            $override = $first !== null && (int) $first !== $batch->id;
        }

        $line = new StockTransferLine;
        $line->forceFill([
            'stock_transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'source_batch_id' => $batch?->id,
            'qty_requested_base' => (string) $qty,
            'fefo_override' => $override,
        ])->save();

        if ($batch !== null) {
            // BR-155: batch draf ditahan; stok kurang → INSUFFICIENT_STOCK dari ledger (galat form).
            $this->ledger->reserve($batch, $qty, new Source(StockTransfer::SOURCE_TYPE, $transfer->id, $line->id));
        }
    }
}
