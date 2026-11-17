<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Approvables\TransferVarianceApproval;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Illuminate\Validation\ValidationException;

/**
 * Terima mutasi per baris di lokasi tujuan (15 §6.6, BR-161, SEC: penerima ≠ pengirim).
 *
 * Satu `StockLedger::transaction`: `PeriodGuard` (tanggal terima lokal) → `move()` TRANSIT →
 * tujuan/rak → outbox `inventory.transferred` SEKALI per penerimaan (`lines: []`, rincian di
 * `refs`) → status. Qty input = jumlah TOTAL yang dihitung per baris (bukan tambahan): > dikirim →
 * `TRANSFER_OVER_RECEIPT`. Kurang dari dikirim → sisa tetap di TRANSIT, status
 * `received_with_variance` + approval `stock_transfer_variance`.
 *
 * Hitung ulang (selisih ditolak/ditarik, PA-66): hanya baris yang masih selisih, qty tidak boleh
 * turun dari yang sudah diterima; tambahan dipindah dari TRANSIT dan dicatat sebagai event
 * `inventory.transferred` revisi berikutnya (outbox unik per dokumen+revisi).
 */
final class ReceiveStockTransfer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
        private readonly ApprovalEngine $engine,
    ) {}

    /**
     * @param  list<array{line_id: int, qty_received: string, dest_bin_id: int|null, variance_reason: string|null}>  $input
     */
    public function handle(StockTransfer $transfer, array $input, User $actor): StockTransfer
    {
        return StockLedger::transaction(function () use ($transfer, $input, $actor) {
            $locked = TransferVarianceApproval::query()->lockForUpdate()->findOrFail($transfer->id);
            if (! $locked->acceptsReceipt()) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Mutasi ini tidak sedang menunggu penerimaan.');
            }
            if ($locked->shipped_by === $actor->id) {
                throw new ApiError(ErrorCode::TRANSFER_SAME_PERSON);
            }
            $recount = $locked->status === StockTransferStatus::ReceivedWithVariance;

            $date = DisplayTime::toLocal(now())->toDateString();
            PeriodGuard::assertOpen($date);

            $to = StockLocation::query()->findOrFail($locked->to_stock_location_id);
            $byId = collect($input)->keyBy('line_id');
            // Posisi baris di form = indeks galat field (`lines.<i>.*`, sama dengan Form Request).
            $position = collect($input)->mapWithKeys(fn (array $r, int $i) => [$r['line_id'] => $i]);
            $open = $locked->lines()->get()->filter(fn (StockTransferLine $l) => ! $recount || $l->variance()->isPositive())->values();
            foreach ($open as $line) {
                if (! $byId->has($line->id)) {
                    throw ValidationException::withMessages(['lines' => 'Isi jumlah diterima untuk setiap baris'.($recount ? ' yang selisih.' : '.')]);
                }
            }

            $items = [];
            foreach ($open as $line) {
                $row = $byId->get($line->id);
                $moved = $this->receiveLine($locked, $line, $row, (int) $position->get($line->id), $to, $date);
                if ($moved !== null) {
                    $items[] = $moved;
                }
            }

            if ($items !== []) {
                // Event jejak tanpa jurnal (15 §11.4). Revisi naik per penerimaan tambahan saat hitung ulang.
                $source = new EventSource(StockTransfer::SOURCE_TYPE, $locked->id);
                AccountingOutbox::record(EventCode::InventoryTransferred, $source, $date, [
                    'lines' => [],
                    'refs' => [
                        'from_stock_location_id' => $locked->from_stock_location_id,
                        'to_stock_location_id' => $locked->to_stock_location_id,
                        'items' => $items,
                    ],
                    'memo' => "Terima mutasi {$locked->number}",
                ], AccountingOutbox::nextRevision(EventCode::InventoryTransferred, $source));
            }

            $before = $locked->audited();
            $locked->unsetRelation('lines');
            $variance = $locked->varianceLines()->isNotEmpty();
            $locked->forceFill([
                'status' => $variance ? StockTransferStatus::ReceivedWithVariance : StockTransferStatus::Received,
                'received_by' => $actor->id, 'received_at' => now(), 'updated_by' => $actor->id,
            ])->save();
            $this->audit->record($recount ? 'stock_transfer.recounted' : 'stock_transfer.received', $locked, $before, [
                ...$locked->audited(), 'lines' => count($items),
            ]);

            if ($variance) {
                // Tanpa aturan + auto approve → onApproved langsung menutup mutasi (request null).
                $request = $this->engine->request($locked, $actor);
                if ($request !== null) {
                    $locked->forceFill(['approval_request_id' => $request->id])->save();
                }
            }

            return $locked->refresh();
        });
    }

    /**
     * @param  array{line_id: int, qty_received: string, dest_bin_id: int|null, variance_reason: string|null}  $row
     * @return array{product_id: int, stock_batch_id: int, qty_base: string, value: string}|null rincian yang dipindah
     */
    private function receiveLine(StockTransfer $transfer, StockTransferLine $line, array $row, int $position, StockLocation $to, string $date): ?array
    {
        $index = "lines.{$position}";
        $counted = Qty::of($row['qty_received']);
        $shipped = $line->qtyShipped();
        if ($counted->compareTo($shipped) > 0) {
            // String = pesan field di form web (InertiaApiErrorRenderer); qty maks ada di teksnya.
            throw new ApiError(ErrorCode::TRANSFER_OVER_RECEIPT, ["{$index}.qty_received" => "Maksimal {$shipped} (jumlah yang dikirim)."]);
        }
        $already = $line->qtyReceived();
        if ($counted->compareTo($already) < 0) {
            throw ValidationException::withMessages(["{$index}.qty_received" => "Sudah diterima {$already}; hitung ulang tidak boleh kurang dari itu."]);
        }
        $reason = $row['variance_reason'] !== null && trim($row['variance_reason']) !== '' ? trim($row['variance_reason']) : null;
        if ($counted->compareTo($shipped) < 0 && $reason === null) {
            throw ValidationException::withMessages(["{$index}.variance_reason" => 'Tulis alasan selisih (barang kurang/rusak/hilang).']);
        }

        // Rak tetap satu per baris: hitung ulang menambah ke rak penerimaan pertama.
        $binId = $line->dest_bin_id ?? $row['dest_bin_id'];
        $bin = null;
        if ($binId !== null) {
            $bin = StockBin::query()->whereKey($binId)->where('stock_location_id', $to->id)->where('is_active', true)->first();
            if ($bin === null) {
                throw ValidationException::withMessages(["{$index}.dest_bin_id" => 'Rak tidak ada di lokasi tujuan atau sudah nonaktif.']);
            }
        }

        $extra = $counted->minus($already);
        $moved = null;
        $destBatchId = $line->dest_batch_id;
        if ($extra->isPositive()) {
            $result = $this->ledger->move(StockBatch::query()->findOrFail($line->transit_batch_id), $bin ?? $to, $extra,
                new Source(StockTransfer::SOURCE_TYPE, $transfer->id, $line->id), MovementType::TransferOut, MovementType::TransferIn,
                $date, "Terima mutasi {$transfer->number}");
            $in = $result->movements[1];
            $destBatchId = $in->stock_batch_id;
            $moved = [
                'product_id' => $line->product_id,
                'stock_batch_id' => $destBatchId,
                'qty_base' => (string) $extra,
                // Nilai sisi masuk saja: `LedgerResult::value()` menjumlah keluar + masuk.
                'value' => (string) Money::of($in->qtyValue()->toBigDecimal()->multipliedBy($in->unitCostValue()->toBigDecimal())),
            ];
        }

        $line->forceFill([
            'qty_received_base' => (string) $counted,
            'dest_bin_id' => $bin?->id,
            'dest_batch_id' => $destBatchId,
            'variance_reason' => $counted->compareTo($shipped) < 0 ? $reason : null,
        ])->save();

        return $moved;
    }
}
