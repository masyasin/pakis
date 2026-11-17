<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Support\OpnameLock;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Illuminate\Validation\ValidationException;

/**
 * Karantina manual (BR-164, 15 §6.6): sebagian/seluruh qty batch `available` dipindah ke rak zona
 * karantina lokasi yang sama (`bin_move_out`/`bin_move_in`); batch tujuan berstatus `quarantine`
 * dengan alasan `quarantine_reason`. Tanpa event akuntansi (lokasi & nilai tidak berubah, 15 §11.4).
 * Batch yang direservasi: hanya qty tersedia yang bisa dikarantina.
 */
final class QuarantineStock
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
    ) {}

    public function handle(StockBatch $batch, Qty $qty, int $reasonId, ?int $binId, ?string $note, User $actor): StockBatch
    {
        return StockLedger::transaction(function () use ($batch, $qty, $reasonId, $binId, $note, $actor) {
            $batch = StockBatch::query()->findOrFail($batch->id);
            if ($batch->status !== StockBatchStatus::Available) {
                throw ValidationException::withMessages(['stock_batch_id' => 'Hanya batch tersedia yang bisa dikarantina.']);
            }
            if (! ReferenceItem::query()->forGroup(ReferenceGroup::QuarantineReason)->where('is_active', true)->whereKey($reasonId)->exists()) {
                throw ValidationException::withMessages(['reason_id' => 'Pilih alasan karantina.']);
            }
            OpnameLock::assertFree([$batch->id]);

            $bin = $binId === null
                ? StockBin::query()->where('stock_location_id', $batch->stock_location_id)->where('code', StockBin::QUARANTINE_CODE)->first()
                : StockBin::query()->whereKey($binId)->where('stock_location_id', $batch->stock_location_id)->where('is_active', true)->first();
            if ($bin === null || $bin->zone !== BinZone::Quarantine) {
                throw ValidationException::withMessages(['stock_bin_id' => 'Pilih rak karantina di lokasi batch ini.']);
            }

            $date = DisplayTime::toLocal(now())->toDateString();
            $result = $this->ledger->move($batch, $bin, $qty, new Source('stock_quarantine', $batch->id), MovementType::BinMoveOut, MovementType::BinMoveIn, $date, $note);
            $dest = $this->ledger->setStatus(StockBatch::query()->findOrFail($result->movements[1]->stock_batch_id), StockBatchStatus::Quarantine, $reasonId, $actor, $note);

            $this->audit->record('stock.quarantined', $dest, null, [
                'from_batch_id' => $batch->id, 'qty_base' => (string) $qty, 'stock_bin_id' => $bin->id, 'quarantine_reason_id' => $reasonId, 'note' => $note,
            ]);

            return $dest;
        });
    }
}
