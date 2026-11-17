<?php

namespace App\Domain\Pricing\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Models\Promotion;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;

/**
 * Dokumen approval `promotion_activate` (15 §4.2.1, BR-169, T-076) = baris promo. Nominal =
 * anggaran (aturan bertingkat per anggaran). Pengaju (maker-checker) = `updated_by` saat diajukan.
 * Disetujui → `active`; ditolak/batal → kembali `draft` (15 §6.6). Persetujuan yang datang setelah
 * `ends_at` lewat ditolak 409 (promo tidak boleh aktif untuk periode yang sudah lewat).
 */
class PromotionActivate extends Promotion implements Approvable
{
    public const DOCUMENT_TYPE = 'promotion_activate';

    protected $table = 'promotions';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function approvalAmount(): ?string
    {
        return $this->budget_amount;
    }

    public function approvalSummary(): string
    {
        return "{$this->code} · {$this->name}".($this->budget_amount !== null ? ' · Anggaran '.Rupiah::format($this->budget_amount) : '');
    }

    public function approvalContext(): array
    {
        $this->loadMissing('principal:id,name');

        return array_filter([
            'Promo' => "{$this->code} · {$this->name}",
            'Tipe' => $this->type->label(),
            'Periode' => DisplayTime::toLocal($this->starts_at)->translatedFormat('j M Y H.i').' – '.DisplayTime::toLocal($this->ends_at)->translatedFormat('j M Y H.i'),
            'Kanal' => implode(', ', $this->channels),
            'Sumber dana' => $this->funded_by->label().($this->principal !== null ? " · {$this->principal->name}" : ''),
            'Anggaran' => $this->budget_amount !== null ? Rupiah::format($this->budget_amount) : 'Tanpa batas',
            'Keterangan' => $this->description,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        return null;
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return null;
    }

    public function creatorUserId(): ?int
    {
        return $this->updated_by;
    }

    public function approvalUrl(): ?string
    {
        return '/promo/'.$this->id.'/ubah';
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== PromotionStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        if ($this->ends_at->lessThanOrEqualTo(now())) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, [], 'Periode promo ini sudah berakhir. Tolak permintaan ini.');
        }
        $before = $this->audited();
        $this->forceFill(['status' => PromotionStatus::Active])->save();
        app(AuditLogger::class)->record('promotion.activated', $this, $before, $this->audited());
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->backToDraft('promotion.rejected');
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->backToDraft('promotion.cancelled');
    }

    private function backToDraft(string $event): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => PromotionStatus::Draft, 'approval_request_id' => null])->save();
        app(AuditLogger::class)->record($event, $this, $before, $this->audited());
    }
}
