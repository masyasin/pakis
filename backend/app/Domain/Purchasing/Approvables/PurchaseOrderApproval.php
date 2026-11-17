<?php

namespace App\Domain\Purchasing\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Dokumen approval `purchase_order` (15 §4.2.1, BR-160, T-079). Nominal = `grand_total`: < Rp 25 jt
 * satu langkah `purchase_orders.approve`; ≥ Rp 25 jt atasan langsung pengaju lalu
 * `purchase_orders.approve_high` (ApprovalRuleSeeder). Scope pegawai = pegawai pembuat PO (untuk
 * `direct_supervisor`); scope lokasi = lokasi induk gudang tujuan. Maker-checker dari mesin: pembuat
 * tidak pernah bisa menyetujui PO-nya sendiri walau memegang izin langkah.
 */
class PurchaseOrderApproval extends PurchaseOrder implements Approvable
{
    public const DOCUMENT_TYPE = 'purchase_order';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    /** Audit tercatat atas PurchaseOrder (bukan kelas turunan ini) agar riwayat dokumen utuh. */
    public function getMorphClass()
    {
        return (new PurchaseOrder)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        return $this->grand_total;
    }

    public function approvalSummary(): string
    {
        $this->loadMissing('supplier:id,name');

        return "{$this->number} · {$this->supplier->name} · ".Rupiah::format($this->grand_total);
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['supplier:id,name', 'stockLocation:id,name']);

        return array_filter([
            'Nomor PO' => $this->number,
            'Supplier' => $this->supplier->name,
            'Gudang tujuan' => $this->stockLocation->name,
            'Tanggal PO' => $this->order_date->translatedFormat('j M Y'),
            'Perkiraan datang' => $this->expected_date?->translatedFormat('j M Y'),
            'Tempo bayar' => $this->payment_term_days === 0 ? 'Tunai' : "{$this->payment_term_days} hari",
            'Jumlah baris' => (string) $this->lines()->count(),
            'Total' => Rupiah::format($this->grand_total),
        ], fn ($v) => $v !== null && $v !== '');
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
        return '/pembelian/po/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== PurchaseOrderStatus::Submitted) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $this->transition(PurchaseOrderStatus::Approved, 'purchase_order.approved', ['approved_at' => now()]);
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->transition(PurchaseOrderStatus::Rejected, 'purchase_order.rejected');
    }

    /** Dibatalkan pengaju sebelum diputuskan: kembali draf agar bisa diperbaiki. */
    public function onCancelled(ApprovalRequest $request): void
    {
        $this->transition(PurchaseOrderStatus::Draft, 'purchase_order.withdrawn', ['approval_request_id' => null, 'submitted_at' => null]);
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(PurchaseOrderStatus $to, string $event, array $extra = []): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => $to, ...$extra])->save();
        app(AuditLogger::class)->record($event, $this, $before, $this->audited());
    }
}
