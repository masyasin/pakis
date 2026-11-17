<?php

namespace App\Domain\Customer\Approvables;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerContact;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Customer\Support\CustomerApprovalScope;
use App\Domain\Customer\Support\PendingCustomerApprovals;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use LogicException;

/**
 * Dokumen approval `customer_merge` (15 §4.2.1, BR-166(5), SEC-110) = baris `customers` kios SUMBER
 * (PA-49), `document_id` = id sumber. Kios tujuan disimpan di `approval_requests.context` dengan
 * kunci mesin `_merge_into_id` (kunci berawalan `_` tidak ditampilkan ke penyetuju, PA-56).
 *
 * Disetujui: sumber `merged` + `merged_into_id`, limitnya dinonaktifkan; kontak & riwayat verifikasi
 * pindah ke tujuan; riwayat penugasan TETAP di sumber (jejak area/sales lama); permintaan approval
 * lain milik sumber yang masih menunggu ditarik (BR-153(10)). Piutang/dokumen terbuka = Paket B
 * (OQ-117).
 */
class CustomerMerge extends Customer implements Approvable
{
    public const DOCUMENT_TYPE = 'customer_merge';

    public const CONTEXT_TARGET = '_merge_into_id';

    /** Status sumber yang boleh digabung (15 §6.6). */
    public const SOURCE_STATUSES = [CustomerStatus::Prospect, CustomerStatus::Active, CustomerStatus::CreditHold];

    protected $table = 'customers';

    /** Kios tujuan saat pengajuan (setelahnya dibaca dari konteks permintaan). */
    public ?Customer $mergeTarget = null;

    public ?string $changeReason = null;

    public function getMorphClass(): string
    {
        return Customer::class;
    }

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
        $target = $this->targetOrFail();

        return "{$this->code} · {$this->store_name} → {$target->code} · {$target->store_name}";
    }

    public function approvalContext(): array
    {
        $target = $this->targetOrFail();
        $this->loadMissing(['salesArea:id,name', 'assignedSales:id,full_name']);

        return array_filter([
            'Kios sumber (digabung)' => "{$this->code} · {$this->store_name} ({$this->status->label()})",
            'Kios tujuan (dipertahankan)' => "{$target->code} · {$target->store_name} ({$target->status->label()})",
            'Area sumber' => $this->salesArea?->name,
            'Sales sumber' => $this->assignedSales?->full_name,
            'Alasan' => $this->changeReason,
            self::CONTEXT_TARGET => $target->id,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function approvalConditions(): array
    {
        return [];
    }

    public function approvalScopeLocationId(): ?int
    {
        return CustomerApprovalScope::locationId($this);
    }

    public function approvalScopeEmployeeId(): ?int
    {
        return CustomerApprovalScope::employeeId($this);
    }

    public function creatorUserId(): ?int
    {
        return null;
    }

    public function approvalUrl(): ?string
    {
        return '/pelanggan/'.$this->id;
    }

    public function onApproved(?ApprovalRequest $request): void
    {
        $targetId = $request !== null ? (int) ($request->context[self::CONTEXT_TARGET] ?? 0) : (int) $this->targetOrFail()->id;

        // Kunci urut id naik agar dua penggabungan bersilangan tidak deadlock.
        $locked = Customer::query()->whereKey([$this->id, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $locked->get($this->id) ?? throw new LogicException("Kios sumber #{$this->id} tidak ditemukan.");
        $target = $locked->get($targetId);
        self::assertMergeable($source, $target);
        /** @var Customer $target */
        app(PendingCustomerApprovals::class)->withdrawAll($source, 'merged');

        $contacts = CustomerContact::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
        $verifications = CustomerVerification::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

        $before = ['status' => $source->status->value, 'merged_into_id' => null, 'credit_limit_active' => $source->credit_limit_active];
        $source->forceFill([
            'status' => CustomerStatus::Merged,
            'merged_into_id' => $target->id,
            'credit_limit_active' => false,
        ])->save();

        app(AuditLogger::class)->record('customer.merged', $source, $before, [
            'status' => CustomerStatus::Merged->value,
            'merged_into_id' => $target->id,
            'merged_into_code' => $target->code,
            'credit_limit_active' => false,
            'contacts_moved' => $contacts,
            'verifications_moved' => $verifications,
            'approval_request_id' => $request?->id,
        ]);
    }

    public function onRejected(ApprovalRequest $request): void {}

    public function onCancelled(ApprovalRequest $request): void {}

    /** Sumber & tujuan masih sah untuk digabung (dicek saat pengajuan dan saat persetujuan akhir). */
    public static function assertMergeable(Customer $source, ?Customer $target): void
    {
        if (! in_array($source->status, self::SOURCE_STATUSES, true)) {
            throw new ApiError(ErrorCode::STATE_CONFLICT,
                publicMessage: 'Kios ini sudah digabung atau nonaktif, jadi tidak bisa digabung.');
        }
        if ($target === null || $target->id === $source->id || $target->trashed() || ! $target->status->isSelectable()) {
            $message = 'Kios tujuan tidak ditemukan, sama dengan kios ini, atau sudah nonaktif/digabung.';
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['target_customer_id' => $message], $message);
        }
    }

    private function targetOrFail(): Customer
    {
        if ($this->mergeTarget !== null) {
            return $this->mergeTarget;
        }
        $id = $this->pendingTargetId();

        return $this->mergeTarget = Customer::query()->withTrashed()->find($id) ?? throw new LogicException("Kios tujuan gabung #{$this->id} tidak diketahui.");
    }

    private function pendingTargetId(): int
    {
        $context = ApprovalRequest::query()->where('document_type', self::DOCUMENT_TYPE)->where('document_id', $this->id)
            ->orderByDesc('id')->value('context');
        $context = is_string($context) ? json_decode($context, true) : $context;

        return is_array($context) ? (int) ($context[self::CONTEXT_TARGET] ?? 0) : 0;
    }
}
