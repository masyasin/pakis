<?php

namespace App\Domain\Inventory\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\DisposalReportLine;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\OpnameLock;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Dokumen approval `stock_disposal` (15 §4.2.1, BR-163, T-084): nominal = `total_cost` (HPP), satu
 * langkah `stock_disposals.approve`. Disetujui → setiap baris direservasi (sumber `disposal_report`
 * boleh mereservasi batch karantina/ED, `StockLedger::RESERVE_ANY_STATUS_SOURCES`, PA-69) agar batch
 * tidak sekaligus diretur/dilepas sebelum dilaksanakan. Ditolak → `rejected`; ditarik → draf.
 */
class DisposalApproval extends DisposalReport implements Approvable
{
    public const DOCUMENT_TYPE = 'stock_disposal';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function getMorphClass()
    {
        return (new DisposalReport)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        return $this->total_cost;
    }

    public function approvalSummary(): string
    {
        $this->loadMissing('stockLocation:id,name');

        return "{$this->number} · {$this->stockLocation->name} · {$this->method->label()} · ".Rupiah::format($this->total_cost);
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['stockLocation:id,name', 'reason:id,name', 'lines.product:id,sku,name', 'lines.batch:id,batch_no']);
        $context = [
            'Nomor BA' => $this->number,
            'Lokasi' => $this->stockLocation->name,
            'Metode' => $this->method->label(),
            'Alasan' => $this->reason->name,
            'Rencana pelaksanaan' => $this->planned_date?->translatedFormat('j M Y'),
            'Nilai (HPP)' => Rupiah::format($this->total_cost),
        ];
        foreach ($this->lines as $line) {
            $context["{$line->product->sku} · {$line->batch->batch_no}"] = (string) $line->qty();
        }

        return array_filter($context, fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        return StockLocation::query()->withTrashed()->whereKey($this->stock_location_id)->value('location_id');
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
        return '/pemusnahan/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== DisposalReportStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $lines = $this->lines()->get();
        OpnameLock::assertFree(array_values(array_map('intval', $lines->pluck('stock_batch_id')->all())));
        $ledger = app(StockLedger::class);
        // Stok kurang sejak diajukan → INSUFFICIENT_STOCK dari ledger, keputusan dibatalkan utuh.
        $lines->each(fn (DisposalReportLine $l) => $ledger->reserve(StockBatch::query()->findOrFail($l->stock_batch_id), $l->qty(),
            new Source(DisposalReport::SOURCE_TYPE, $this->id, $l->id)));

        $this->transition(DisposalReportStatus::Approved, 'disposal_report.approved');
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->transition(DisposalReportStatus::Rejected, 'disposal_report.rejected');
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->transition(DisposalReportStatus::Draft, 'disposal_report.withdrawn', ['approval_request_id' => null]);
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(DisposalReportStatus $to, string $event, array $extra = []): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => $to, ...$extra])->save();
        app(AuditLogger::class)->record($event, $this, $before, $this->audited());
    }
}
