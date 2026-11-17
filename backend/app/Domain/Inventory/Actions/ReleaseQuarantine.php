<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Approvables\QuarantineReleaseApproval;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\QuarantineReleaseStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\QuarantineRelease;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Support\OpnameLock;
use App\Models\User;
use App\Support\Money\Qty;
use Illuminate\Validation\ValidationException;

/**
 * Ajukan lepas karantina (BR-164, 15 §5 `admin.stock.release`): batch `quarantine` (BUKAN `expired`
 * — kedaluwarsa hanya dimusnahkan/diretur) → permintaan `stock_quarantine_releases` (qty + rak
 * tujuan non-karantina, PA-69) → approval `stock_quarantine_release`. Stok baru pindah saat
 * disetujui (QuarantineReleaseApproval). Satu permintaan aktif per batch.
 */
final class ReleaseQuarantine
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovalEngine $engine,
    ) {}

    public function handle(StockBatch $batch, Qty $qty, int $binId, string $reason, User $actor): QuarantineRelease
    {
        return StockLedger::transaction(function () use ($batch, $qty, $binId, $reason, $actor) {
            $batch = StockBatch::query()->findOrFail($batch->id);
            if ($batch->status === StockBatchStatus::Expired) {
                throw ValidationException::withMessages(['stock_batch_id' => 'Batch kedaluwarsa tidak bisa dilepas karantina. Musnahkan atau retur ke prinsipal.']);
            }
            if ($batch->status !== StockBatchStatus::Quarantine) {
                throw ValidationException::withMessages(['stock_batch_id' => 'Batch ini tidak sedang dikarantina.']);
            }
            if (! $qty->isPositive() || $qty->compareTo($batch->available()) > 0) {
                throw ValidationException::withMessages(['qty_base' => "Jumlah dilepas maksimal {$batch->available()}."]);
            }
            $bin = StockBin::query()->whereKey($binId)->where('stock_location_id', $batch->stock_location_id)->where('is_active', true)->first();
            if ($bin === null || $bin->zone === BinZone::Quarantine) {
                throw ValidationException::withMessages(['target_bin_id' => 'Pilih rak aktif non-karantina di lokasi yang sama.']);
            }
            OpnameLock::assertFree([$batch->id]);
            $pending = QuarantineRelease::query()->where('stock_batch_id', $batch->id)->where('status', QuarantineReleaseStatus::Pending->value)->lockForUpdate()->exists();
            if ($pending) {
                throw ValidationException::withMessages(['stock_batch_id' => 'Batch ini sudah punya permintaan lepas karantina yang menunggu.']);
            }

            $release = new QuarantineReleaseApproval;
            $release->forceFill([
                'stock_batch_id' => $batch->id,
                'qty_base' => (string) $qty,
                'target_bin_id' => $bin->id,
                'reason' => $reason,
                'status' => QuarantineReleaseStatus::Pending,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            $this->audit->record('stock.release_requested', $release, null, $release->audited());

            // Tanpa aturan + auto approve → onApproved langsung memindah stok (request null).
            $request = $this->engine->request($release, $actor);
            if ($request !== null) {
                $release->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $release->refresh();
        });
    }
}
