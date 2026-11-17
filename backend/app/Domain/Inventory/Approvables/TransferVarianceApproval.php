<?php

namespace App\Domain\Inventory\Approvables;

use App\Domain\Accounting\Outbox\AccountingOutbox;
use App\Domain\Accounting\Outbox\EventCode;
use App\Domain\Accounting\Outbox\EventSource;
use App\Domain\Accounting\Period\PeriodGuard;
use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockTransfer;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Money;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Brick\Math\BigDecimal;

/**
 * Dokumen approval `stock_transfer_variance` (15 §4.2.1, BR-161, T-082): penerimaan mutasi yang
 * kurang dari kiriman. Nominal = Σ selisih × HPP rata-rata saat ini; satu langkah
 * `stock_transfers.approve_variance` (ApprovalRuleSeeder). Scope lokasi = lokasi induk gudang
 * TUJUAN (penyetuju = kepala gudang penerima); maker-checker = penerima terakhir.
 *
 * Disetujui → `adjustment_out` sisa di TRANSIT + outbox `inventory.transfer_variance` (bukan
 * `inventory.adjusted`, 15 §11.4) → `closed`. Ditolak/ditarik → baris selisih dibuka kembali
 * untuk dihitung ulang penerima (keputusan Mas, PA-66), status tetap `received_with_variance`.
 */
class TransferVarianceApproval extends StockTransfer implements Approvable
{
    public const DOCUMENT_TYPE = 'stock_transfer_variance';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    /** Audit tercatat atas StockTransfer (bukan kelas turunan ini) agar riwayat dokumen utuh. */
    public function getMorphClass()
    {
        return (new StockTransfer)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        return (string) $this->varianceValue();
    }

    public function approvalSummary(): string
    {
        $this->loadMissing(['fromLocation:id,name', 'toLocation:id,name']);

        return "{$this->number} · {$this->fromLocation->name} → {$this->toLocation->name} · selisih ".Rupiah::format((string) $this->varianceValue());
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['fromLocation:id,name', 'toLocation:id,name', 'shipper:id,name', 'receiver:id,name', 'lines.product:id,sku,name']);
        $context = [
            'Nomor mutasi' => $this->number,
            'Dari' => $this->fromLocation->name,
            'Ke' => $this->toLocation->name,
            'Dikirim oleh' => $this->shipper?->name,
            'Diterima oleh' => $this->receiver?->name,
            'Nilai selisih' => Rupiah::format((string) $this->varianceValue()),
        ];
        foreach ($this->varianceLines() as $line) {
            $label = "Selisih {$line->product->sku}";
            $context[$label] = "{$line->variance()} dari {$line->qtyShipped()} (".($line->variance_reason ?? 'tanpa alasan').')';
        }

        return array_filter($context, fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        return StockLocation::query()->withTrashed()->whereKey($this->to_stock_location_id)->value('location_id');
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return $this->received_by === null ? null : User::query()->whereKey($this->received_by)->value('employee_id');
    }

    /** Penerima yang melaporkan selisih tidak boleh menyetujuinya sendiri (BR-153(4)). */
    public function creatorUserId(): ?int
    {
        return $this->received_by;
    }

    public function approvalUrl(): ?string
    {
        return '/mutasi/'.$this->id;
    }

    /**
     * Sisa selisih keluar dari TRANSIT pada tanggal putusan (tanggal bisnis = hari keputusan, lokal).
     * Dipanggil mesin di dalam transaksi keputusan; StockLedger memakai transaksi itu.
     */
    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== StockTransferStatus::ReceivedWithVariance) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $date = DisplayTime::toLocal(now())->toDateString();
        PeriodGuard::assertOpen($date);

        $ledger = app(StockLedger::class);
        $transit = StockLocation::query()->where('code', StockLocation::TRANSIT_CODE)->sole();
        $journal = [];
        $total = Money::zero();
        foreach ($this->varianceLines() as $line) {
            $batch = StockBatch::query()->findOrFail($line->transit_batch_id);
            $result = $ledger->adjust($batch, Qty::of($line->variance()->toBigDecimal()->negated()),
                new Source(StockTransfer::SOURCE_TYPE, $this->id, $line->id), null, $date, note: $line->variance_reason);
            $value = (string) $result->value();
            $total = $total->plus($result->value());
            $dims = ['stock_location_id' => $transit->id, 'product_id' => $line->product_id];
            $journal[] = ['role' => 'inventory_loss', 'debit' => $value, ...$dims];
            $journal[] = ['role' => 'inventory', 'credit' => $value, ...$dims];
        }

        AccountingOutbox::record(EventCode::InventoryTransferVariance, new EventSource(StockTransfer::SOURCE_TYPE, $this->id), $date, [
            'lines' => $journal,
            'refs' => ['from_stock_location_id' => $this->from_stock_location_id, 'to_stock_location_id' => $this->to_stock_location_id],
            'memo' => "Selisih mutasi {$this->number}",
        ]);

        $this->transition(StockTransferStatus::Closed, 'stock_transfer.variance_approved', audit: ['variance_value' => (string) $total]);
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->reopen('stock_transfer.variance_rejected');
    }

    /** Ditarik penerima sebelum diputuskan: sama dengan ditolak, baris dibuka untuk hitung ulang. */
    public function onCancelled(ApprovalRequest $request): void
    {
        $this->reopen('stock_transfer.variance_withdrawn');
    }

    /**
     * Σ selisih × HPP rata-rata saat ini, dibulatkan ke rupiah (nominal aturan approval). HPP dibaca
     * dari `product_costs` (baca saja; CostCalculator khusus Ledger, BR-159); belum ada = 0.
     */
    public function varianceValue(): Money
    {
        $lines = $this->varianceLines();
        $costs = ProductCost::query()->whereIn('product_id', $lines->pluck('product_id'))->get()->keyBy('product_id');
        $total = Money::zero()->toBigDecimal();
        foreach ($lines as $line) {
            $cost = $costs->get($line->product_id)?->avgCost()->toBigDecimal() ?? BigDecimal::zero();
            $total = $total->plus($line->variance()->toBigDecimal()->multipliedBy($cost));
        }

        return Money::of($total);
    }

    /** `approval_request_id` kosong + status tetap = baris selisih terbuka untuk dihitung ulang (Policy `receive`). */
    private function reopen(string $event): void
    {
        if ($this->status !== StockTransferStatus::ReceivedWithVariance) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $this->transition(StockTransferStatus::ReceivedWithVariance, $event, ['approval_request_id' => null]);
    }

    /**
     * @param  array<string, mixed>  $columns  kolom tambahan yang disimpan
     * @param  array<string, mixed>  $audit  nilai tambahan yang hanya dicatat di audit
     */
    private function transition(StockTransferStatus $to, string $event, array $columns = [], array $audit = []): void
    {
        $before = $this->audited();
        $this->forceFill(['status' => $to, 'updated_by' => auth()->id() ?? $this->updated_by, ...$columns])->save();
        app(AuditLogger::class)->record($event, $this, $before, [...$this->audited(), ...$audit]);
    }
}
