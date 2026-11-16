<?php

namespace App\Domain\Pricing\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Pricing\Actions\PublishPriceVersion;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;

/**
 * Dokumen approval `price_list_publish` (15 §4.2.1, BR-168, T-075) = baris versi daftar harga.
 * Rantai bawaan: satu langkah `prices.publish` (ApprovalRuleSeeder). Pengaju (maker-checker
 * SEC-101) = `updated_by` saat diajukan (diisi SubmitPriceVersion), bukan pembuat draf, karena
 * isi draf bisa disunting beberapa orang sebelum diajukan.
 */
class PriceListPublish extends PriceListVersion implements Approvable
{
    public const DOCUMENT_TYPE = 'price_list_publish';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function approvalAmount(): ?string
    {
        return null;
    }

    public function approvalSummary(): string
    {
        $list = $this->priceList;

        return "{$list->code} · {$list->name} · versi {$this->version_no}";
    }

    public function approvalContext(): array
    {
        $list = $this->priceList;

        return array_filter([
            'Daftar harga' => "{$list->code} · {$list->name}",
            'Jenis' => $list->kind->label(),
            'Versi' => (string) $this->version_no,
            'Berlaku mulai' => DisplayTime::toLocal($this->effective_from)->translatedFormat('j M Y H.i'),
            'Jumlah baris harga' => (string) $this->items()->count(),
            'Catatan' => $this->note,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    /** List toko: lokasi induk toko itu (scope kepala lokasi); list lain berlaku lintas lokasi. */
    public function approvalScopeLocationId(): ?int
    {
        $list = $this->priceList;

        return $list->kind === PriceListKind::Store && $list->stock_location_id !== null
            ? StockLocation::query()->withTrashed()->whereKey($list->stock_location_id)->value('location_id')
            : null;
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
        return '/daftar-harga/versi/'.$this->id.'/item';
    }

    /** Disetujui: terbit. Versi harus masih menunggu (bukan sudah diubah jalur lain). */
    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== PriceVersionStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        // Penerbit = pemutus langkah terakhir (null bila disetujui otomatis tanpa aturan).
        $decider = $request?->steps()->whereNotNull('decided_by')->orderByDesc('step_order')->value('decided_by');
        app(PublishPriceVersion::class)->handle($this, $decider === null ? null : (int) $decider);
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => PriceVersionStatus::Rejected])->save();
        app(AuditLogger::class)->record('price_version.rejected', $this, $before, $this->audited());
    }

    /** Dibatalkan pengaju: kembali draf agar bisa disunting lagi. */
    public function onCancelled(ApprovalRequest $request): void
    {
        $this->forceFill(['status' => PriceVersionStatus::Draft, 'approval_request_id' => null])->save();
    }
}
