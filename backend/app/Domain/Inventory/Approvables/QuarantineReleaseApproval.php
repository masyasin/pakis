<?php

namespace App\Domain\Inventory\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\QuarantineReleaseStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\QuarantineRelease;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\OpnameLock;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;

/**
 * Dokumen approval `stock_quarantine_release` (15 §4.2.1, BR-164, T-084): satu langkah
 * `stock_quarantine.release`, tanpa nominal. Disetujui → qty pindah dari rak karantina ke rak tujuan
 * (`bin_move_*`, tanpa event 15 §11.4), batch tujuan `available`. Ditolak/ditarik → batch tetap karantina.
 */
class QuarantineReleaseApproval extends QuarantineRelease implements Approvable
{
    public const DOCUMENT_TYPE = 'stock_quarantine_release';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function getMorphClass()
    {
        return (new QuarantineRelease)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        return null;
    }

    public function approvalSummary(): string
    {
        $this->loadMissing(['batch.product:id,sku,name', 'batch.stockLocation:id,name']);

        return "Lepas karantina {$this->batch->product->sku} batch {$this->batch->batch_no} · ".Qty::of($this->qty_base).' · '.$this->batch->stockLocation->name;
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['batch.product:id,sku,name', 'batch.stockLocation:id,name', 'targetBin:id,code', 'creator:id,name']);

        return array_filter([
            'Produk' => "{$this->batch->product->sku} · {$this->batch->product->name}",
            'Batch' => $this->batch->batch_no,
            'ED' => $this->batch->expiry_date?->translatedFormat('j M Y'),
            'Lokasi' => $this->batch->stockLocation->name,
            'Jumlah dilepas' => (string) Qty::of($this->qty_base),
            'Rak tujuan' => $this->targetBin->code,
            'Alasan' => $this->reason,
            'Diajukan oleh' => $this->creator?->name,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        $locationId = StockBatch::query()->whereKey($this->stock_batch_id)->value('stock_location_id');

        return StockLocation::query()->withTrashed()->whereKey($locationId)->value('location_id');
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return $this->created_by === null ? null : User::query()->whereKey($this->created_by)->value('employee_id');
    }

    public function creatorUserId(): ?int
    {
        return $this->created_by;
    }

    public function approvalUrl(): ?string
    {
        return '/stok/karantina';
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== QuarantineReleaseStatus::Pending) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $batch = StockBatch::query()->findOrFail($this->stock_batch_id);
        // Status diperiksa ulang saat putusan: batch bisa kedaluwarsa (job 00.15) selama menunggu.
        if ($batch->status !== StockBatchStatus::Quarantine) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['batch_status' => $batch->status->value],
                'Batch ini tidak lagi berstatus karantina (mungkin sudah kedaluwarsa). Tolak permintaan ini.');
        }
        OpnameLock::assertFree([$batch->id]);

        $result = app(StockLedger::class)->move($batch, StockBin::query()->findOrFail($this->target_bin_id), Qty::of($this->qty_base),
            new Source(QuarantineRelease::SOURCE_TYPE, $this->id), MovementType::BinMoveOut, MovementType::BinMoveIn,
            DisplayTime::toLocal(now())->toDateString(), 'Lepas karantina');

        $this->transition(QuarantineReleaseStatus::Approved, 'stock.released', ['released_batch_id' => $result->movements[1]->stock_batch_id]);
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->transition(QuarantineReleaseStatus::Rejected, 'stock.release_rejected');
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->transition(QuarantineReleaseStatus::Cancelled, 'stock.release_withdrawn');
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(QuarantineReleaseStatus $to, string $event, array $extra = []): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => $to, ...$extra])->save();
        app(AuditLogger::class)->record($event, $this, $before, $this->audited());
    }
}
