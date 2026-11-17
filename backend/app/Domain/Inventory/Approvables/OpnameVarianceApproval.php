<?php

namespace App\Domain\Inventory\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Actions\PostStockOpname;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Domain\Inventory\Support\OpnameVariance;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;

/**
 * Dokumen approval `stock_opname_variance` (15 §4.2.1, BR-162, T-083). Nominal = `variance_value`
 * (Σ |selisih| × HPP saat diajukan): < Rp 1 jt `stock_opnames.approve_variance`, ≥ Rp 1 jt
 * ditambah `stock_opnames.approve_variance_high` (ApprovalRuleSeeder). Scope lokasi = lokasi induk
 * gudang; maker-checker = pengaju posting (`updated_by` saat diajukan).
 *
 * Disetujui → PostStockOpname dengan tanggal keputusan (keputusan Mas, PA-67). Ditolak/ditarik →
 * kembali `review`, hitung ulang baris selisih dihapus agar wajib dihitung ulang orang lain.
 */
class OpnameVarianceApproval extends StockOpname implements Approvable
{
    public const DOCUMENT_TYPE = 'stock_opname_variance';

    public function approvalDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    /** Audit tercatat atas StockOpname (bukan kelas turunan ini) agar riwayat dokumen utuh. */
    public function getMorphClass()
    {
        return (new StockOpname)->getMorphClass();
    }

    public function approvalAmount(): ?string
    {
        return $this->variance_value;
    }

    public function approvalSummary(): string
    {
        $this->loadMissing('stockLocation:id,name');

        return "{$this->number} · {$this->stockLocation->name} · selisih ".Rupiah::format((string) $this->variance_value);
    }

    public function approvalContext(): array
    {
        $this->loadMissing(['stockLocation:id,name', 'lines']);
        $variances = OpnameVariance::for($this);
        $count = $this->lines->filter(fn (StockOpnameLine $l) => OpnameVariance::hasVariance($variances[$l->id]))->count();

        return array_filter([
            'Nomor opname' => $this->number,
            'Lokasi' => $this->stockLocation->name,
            'Cakupan' => $this->scope->label(),
            'Hitung buta' => $this->blind_count ? 'Ya' : 'Tidak',
            'Mulai dihitung' => $this->frozen_at === null ? null : DisplayTime::toLocal($this->frozen_at)->translatedFormat('j M Y H.i'),
            'Jumlah baris' => (string) $this->lines->count(),
            'Baris selisih' => (string) $count,
            'Nilai selisih' => Rupiah::format((string) $this->variance_value),
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
        return $this->updated_by === null ? null : User::query()->whereKey($this->updated_by)->value('employee_id');
    }

    public function creatorUserId(): ?int
    {
        return $this->updated_by;
    }

    public function approvalUrl(): ?string
    {
        return '/opname/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        if ($this->status !== StockOpnameStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        if ($request !== null) {
            $this->assertApproversDidNotCount($request);
        }
        app(PostStockOpname::class)->handle($this, DisplayTime::toLocal(now())->toDateString(), $this->updated_by,
            $request === null ? null : $this->variance_value);
    }

    /**
     * Pemisahan tugas (SEC-103, BR-153(4)): mesin hanya menolak pengaju; orang yang menghitung atau
     * menghitung ulang baris selisih juga tidak boleh menyetujui selisih itu di langkah mana pun.
     */
    private function assertApproversDidNotCount(ApprovalRequest $request): void
    {
        $this->setRelation('lines', $this->lines()->get());
        $variances = OpnameVariance::for($this);
        $counters = $this->lines->filter(fn (StockOpnameLine $l) => OpnameVariance::hasVariance($variances[$l->id]))
            ->flatMap(fn (StockOpnameLine $l) => [$l->counted_by, $l->recounted_by])->filter()->unique();
        $approvers = ApprovalStep::query()->where('approval_request_id', $request->id)->whereNotNull('decided_by')->pluck('decided_by');
        if ($approvers->intersect($counters)->isNotEmpty()) {
            throw new ApiError(ErrorCode::APPROVAL_SELF_DECISION, publicMessage: 'Penghitung baris selisih tidak boleh menyetujui selisih opname ini.');
        }
    }

    public function onRejected(ApprovalRequest $request): void
    {
        $this->reopen('stock_opname.variance_rejected');
    }

    public function onCancelled(ApprovalRequest $request): void
    {
        $this->reopen('stock_opname.variance_withdrawn');
    }

    private function reopen(string $event): void
    {
        if ($this->status !== StockOpnameStatus::PendingApproval) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $this->status->value]);
        }
        $this->setRelation('lines', $this->lines()->get());
        $variances = OpnameVariance::for($this);
        $reopened = $this->lines->filter(fn (StockOpnameLine $l) => OpnameVariance::hasVariance($variances[$l->id]))->pluck('id');
        StockOpnameLine::query()->whereIn('id', $reopened)->update(['qty_recount_base' => null, 'recounted_by' => null, 'recounted_at' => null]);

        $before = $this->audited();
        $this->forceFill(['status' => StockOpnameStatus::Review, 'approval_request_id' => null])->save();
        app(AuditLogger::class)->record($event, $this, $before, [...$this->audited(), 'reopened_lines' => $reopened->count()]);
    }
}
