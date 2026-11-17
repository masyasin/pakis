<?php

namespace App\Domain\Purchasing\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen approval `goods_receipt_short_shelf_life` (15 §4.2.1, BR-103, T-080): penerimaan dengan
 * baris sisa umur di bawah batas. Tanpa nominal; satu langkah `location_head` (kepala lokasi induk
 * gudang) lalu eskalasi ke `goods_receipts.approve_short_shelf_life` (ApprovalRuleSeeder).
 *
 * Disetujui → posting inti (stok masuk). Ditolak → `rejected`, stok tidak berubah, nomor seri baris
 * dihapus (belum pernah masuk stok, PA-65). Ditarik pengaju → kembali `checked`.
 */
class ShortShelfLifeApproval extends GoodsReceipt implements Approvable
{
    public const DOCUMENT_TYPE = 'goods_receipt_short_shelf_life';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    /** Audit tercatat atas GoodsReceipt agar riwayat dokumen utuh. */
    public function getMorphClass()
    {
        return (new GoodsReceipt)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        return null;
    }

    public function approvalSummary(): string
    {
        $this->loadMissing('supplier:id,name');
        $short = $this->shortLines()->count();

        return "{$this->number} · {$this->supplier->name} · {$short} baris sisa umur pendek";
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['supplier:id,name', 'stockLocation:id,name', 'purchaseOrder:id,number']);
        $limit = (int) setting('inventory.min_shelf_life_months');
        $lines = $this->shortLines()->with('product:id,sku,name,min_shelf_life_months')->get()
            ->map(fn (GoodsReceiptLine $l) => sprintf('%s · batch %s · ED %s · sisa %s bln (batas %d)',
                $l->product->sku, $l->batch_no, $l->expiry_date?->translatedFormat('j M Y') ?? '-',
                str_replace('.', ',', (string) $l->shelf_life_months), $l->product->min_shelf_life_months ?? $limit))
            ->implode('; ');

        return array_filter([
            'Nomor penerimaan' => $this->number,
            'PO' => $this->purchaseOrder?->number,
            'Supplier' => $this->supplier->name,
            'Gudang' => $this->stockLocation->name,
            'Tanggal terima' => DisplayTime::toLocal($this->received_at)->translatedFormat('j M Y H.i'),
            'Baris sisa umur pendek' => $lines,
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
        $userId = $this->posted_by ?? $this->created_by;

        return $userId === null ? null : User::query()->whereKey($userId)->value('employee_id');
    }

    /** Pengaju = yang menekan posting (dicatat di `posted_by` saat menunggu); maker-checker dari mesin. */
    public function creatorUserId(): ?int
    {
        return $this->posted_by ?? $this->created_by;
    }

    public function approvalUrl(): ?string
    {
        return '/penerimaan/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== GoodsReceiptStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $before = $this->audited();
        app(AuditLogger::class)->record('goods_receipt.approved', $this, $before, $before);
        app(PostGoodsReceipt::class)->postApproved($this);
    }

    public function onRejected(ApprovalRequest $request): void
    {
        // Nomor seri belum pernah masuk stok (stock_batch_id NULL) → dihapus agar bisa dipakai GR lain (PA-65).
        SerialNumber::query()->whereIn('goods_receipt_line_id', $this->lines()->select('id'))->whereNull('stock_batch_id')->delete();
        $this->transition(GoodsReceiptStatus::Rejected, 'goods_receipt.rejected');
    }

    /** Ditarik pengaju sebelum diputuskan: kembali `checked` agar bisa diperbaiki (ubah → draf) atau diajukan lagi. */
    public function onCancelled(ApprovalRequest $request): void
    {
        $this->transition(GoodsReceiptStatus::Checked, 'goods_receipt.withdrawn', ['approval_request_id' => null, 'posted_by' => null]);
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    private function shortLines()
    {
        return $this->lines()->where('line_status', ReceiptLineStatus::ShortShelfLife->value);
    }

    /** @param  array<string, mixed>  $extra */
    private function transition(GoodsReceiptStatus $to, string $event, array $extra = []): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => $to, ...$extra])->save();
        app(AuditLogger::class)->record($event, $this, $before, $this->audited());
    }
}
