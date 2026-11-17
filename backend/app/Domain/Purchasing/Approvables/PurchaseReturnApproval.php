<?php

namespace App\Domain\Purchasing\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Dokumen approval `purchase_return` (15 §4.2.1, FR-A26, T-081): nominal = Σ qty × HPP rata-rata saat
 * diajukan, satu langkah `purchase_returns.approve` (ApprovalRuleSeeder). Scope lokasi = lokasi induk
 * lokasi stok. Disetujui → `approved` + reservasi per baris (BR-155; batch karantina/ED boleh, PA-68);
 * qty kurang → `INSUFFICIENT_STOCK` dan persetujuan batal utuh. Ditolak → `rejected` (belum ada
 * reservasi). Ditarik → `draft`.
 */
class PurchaseReturnApproval extends PurchaseReturn implements Approvable
{
    public const DOCUMENT_TYPE = 'purchase_return';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    /** Audit tercatat atas PurchaseReturn agar riwayat dokumen utuh. */
    public function getMorphClass()
    {
        return (new PurchaseReturn)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        $lines = $this->lines()->get(['product_id', 'qty_base']);
        $costs = ProductCost::query()->whereIn('product_id', $lines->pluck('product_id')->unique())->get()->keyBy('product_id');
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $avg = $costs->get($line->product_id)->avg_cost ?? '0';
            $total = $total->plus(BigDecimal::of($line->qty_base)->multipliedBy((string) $avg));
        }

        return (string) $total->toScale(2, RoundingMode::HalfUp);
    }

    public function approvalSummary(): string
    {
        $this->loadMissing('supplier:id,name');

        return "{$this->number} · {$this->supplier->name} · ".Rupiah::format((string) $this->approvalAmount());
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['supplier:id,name', 'stockLocation:id,name', 'reason:id,name', 'goodsReceipt:id,number']);

        return array_filter([
            'Nomor retur' => $this->number,
            'Supplier' => $this->supplier->name,
            'Lokasi stok' => $this->stockLocation->name,
            'Alasan' => $this->reason->name,
            'Penerimaan' => $this->goodsReceipt?->number,
            'Jumlah baris' => (string) $this->lines()->count(),
            'Nilai (HPP)' => Rupiah::format((string) $this->approvalAmount()),
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
        return '/pembelian/retur/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== PurchaseReturnStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }

        StockLedger::transaction(function () {
            $ledger = app(StockLedger::class);
            $lines = PurchaseReturnLine::query()->where('purchase_return_id', $this->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $batch = StockBatch::query()->findOrFail($line->stock_batch_id);
                $reservation = $ledger->reserve($batch, Qty::of($line->qty_base), new Source('purchase_return', $this->id, $line->id));
                $line->forceFill(['reservation_id' => $reservation->id])->save();
            }
            $this->transition(PurchaseReturnStatus::Approved, 'purchase_return.approved');
        });
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->transition(PurchaseReturnStatus::Rejected, 'purchase_return.rejected');
    }

    /** Ditarik pengaju sebelum diputuskan: kembali draf agar bisa diperbaiki. */
    public function onCancelled(ApprovalRequest $request): void
    {
        $this->transition(PurchaseReturnStatus::Draft, 'purchase_return.withdrawn', ['approval_request_id' => null]);
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(PurchaseReturnStatus $to, string $event, array $extra = []): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => $to, ...$extra])->save();
        app(AuditLogger::class)->record($event, $this, $before, $this->audited());
    }
}
