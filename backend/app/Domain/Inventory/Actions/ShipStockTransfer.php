<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Inventory\Allocation\FefoAllocator;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Ledger\LedgerResult;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Kirim mutasi draf (15 §6.6, BR-161): stok pindah dari batch asal ke batch bayangan di TRANSIT
 * (nomor, ED, tanggal terima, biaya beli sama) lewat `move()` (`transfer_out` + `transfer_in`).
 * Tanpa event akuntansi (15 §11.4: event baru saat terima), jadi tanpa PeriodGuard.
 *
 * - Baris berbatch: reservasi draf dilepas lalu dipindah di transaksi yang sama (batch tetap
 *   terkunci, BR-155); `consume()` tidak dipakai karena tidak membuat batch bayangan (PA-66).
 *   Reservasi yang sudah kedaluwarsa tidak menggagalkan kirim selama qty masih tersedia.
 * - Baris tanpa batch: `FefoAllocator` pada tanggal kirim; bila terbagi ke beberapa batch, baris
 *   dipecah per batch agar telusur batch tetap satu-satu (PA-66).
 */
final class ShipStockTransfer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
        private readonly FefoAllocator $allocator,
    ) {}

    public function handle(StockTransfer $transfer, User $actor): StockTransfer
    {
        return StockLedger::transaction(function () use ($transfer, $actor) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== StockTransferStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya mutasi draf yang bisa dikirim.');
            }
            $lines = $locked->lines()->with('product')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Isi minimal satu baris produk sebelum mutasi dikirim.']);
            }
            $from = StockLocation::query()->findOrFail($locked->from_stock_location_id);
            $to = StockLocation::query()->findOrFail($locked->to_stock_location_id);
            if (! $from->is_active || ! $to->is_active) {
                throw ValidationException::withMessages(['to_stock_location_id' => 'Lokasi asal atau tujuan sudah nonaktif.']);
            }

            $date = DisplayTime::toLocal(now())->toDateString();
            $transit = StockLocation::query()->where('code', StockLocation::TRANSIT_CODE)->sole();
            SaveStockTransfer::releaseReservations($this->ledger, $locked);

            foreach ($lines as $line) {
                if ($line->source_batch_id !== null) {
                    $this->assertRegisteredForStore($line->product, $to, $date);
                    $batch = StockBatch::query()->findOrFail($line->source_batch_id);
                    $this->ship($line, $batch, $line->qtyRequested(), $transit, $date, $locked);

                    continue;
                }

                $allocation = $this->allocator->allocate($line->product, $from, $line->qtyRequested(), $date);
                foreach ($allocation->lines as $i => $part) {
                    $target = $i === 0 ? $line : $this->split($line, $part->qty);
                    if ($i === 0 && count($allocation->lines) > 1) {
                        $target->forceFill(['qty_requested_base' => (string) $part->qty])->save();
                    }
                    $this->ship($target, $part->batch, $part->qty, $transit, $date, $locked);
                }
            }

            $before = $locked->audited();
            $locked->forceFill([
                'status' => StockTransferStatus::Shipped, 'shipped_by' => $actor->id, 'shipped_at' => now(), 'updated_by' => $actor->id,
            ])->save();
            $this->audit->record('stock_transfer.shipped', $locked, $before, [...$locked->audited(), 'lines' => $locked->lines()->count()]);

            return $locked;
        });
    }

    private function ship(StockTransferLine $line, StockBatch $batch, Qty $qty, StockLocation $transit, string $date, StockTransfer $transfer): LedgerResult
    {
        $result = $this->ledger->move($batch, $transit, $qty, new Source(StockTransfer::SOURCE_TYPE, $transfer->id, $line->id),
            MovementType::TransferOut, MovementType::TransferIn, $date, "Mutasi {$transfer->number}");

        $line->forceFill([
            'source_batch_id' => $batch->id,
            'transit_batch_id' => $result->movements[1]->stock_batch_id,
            'qty_shipped_base' => (string) $qty,
        ])->save();

        return $result;
    }

    /** Baris baru untuk batch FEFO berikutnya (qty diminta = bagian batch itu). */
    private function split(StockTransferLine $line, Qty $qty): StockTransferLine
    {
        $copy = new StockTransferLine;
        $copy->forceFill([
            'stock_transfer_id' => $line->stock_transfer_id,
            'product_id' => $line->product_id,
            'qty_requested_base' => (string) $qty,
            'fefo_override' => false,
        ])->save();

        return $copy;
    }

    /**
     * BR-152: produk wajib izin tanpa izin berlaku tidak boleh dimutasi ke toko. Baris FEFO sudah
     * diperiksa FefoAllocator; baris batch pilihan diperiksa di sini.
     */
    private function assertRegisteredForStore(Product $product, StockLocation $to, string $date): void
    {
        if (! $product->requires_registration || $to->type !== StockLocationType::Store) {
            return;
        }
        $moment = CarbonImmutable::parse("{$date} 12:00:00", DisplayTime::timezone());
        if (ActiveRegistration::for($product, $moment) === null) {
            throw new ApiError(ErrorCode::PRODUCT_REGISTRATION_INACTIVE, ['product_id' => $product->id]);
        }
    }
}
