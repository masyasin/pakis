<?php

namespace App\Domain\Customer\Support;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Approvables\CustomerCreditLimit;
use App\Domain\Customer\Approvables\CustomerMerge;
use App\Domain\Customer\Approvables\CustomerPhoneChange;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;

/**
 * Tarik semua permintaan approval kios yang masih menunggu (BR-153(10)) saat kiosnya berubah
 * mendasar: digabung ke kios lain atau dinonaktifkan. Keputusan atas kios yang sudah tidak berlaku
 * hanya akan menimbulkan 409 di penyetuju, jadi lebih jujur ditarik sekarang + diaudit.
 *
 * Dipanggil DI DALAM transaksi pemanggil (kios sudah dikunci).
 */
final class PendingCustomerApprovals
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
        private readonly OfficeCallDrafts $drafts,
    ) {}

    /** @return int jumlah permintaan yang ditarik */
    public function withdrawAll(Customer $customer, string $reason): int
    {
        $this->drafts->discard($customer->id);
        $count = 0;

        foreach ([CustomerActivation::class, CustomerPhoneChange::class] as $class) {
            $pending = $class::query()->where('customer_id', $customer->id)->where('status', VerificationStatus::Pending)->get();
            foreach ($pending as $verification) {
                $this->engine->withdraw($verification);
                $verification->forceFill(['status' => VerificationStatus::Cancelled])->save();
                $this->audit->record('customer.verification_withdrawn', $customer, null, [
                    'verification_id' => $verification->id,
                    'type' => $verification->type->value,
                    'reason' => $reason,
                ]);
                $count++;
            }
        }

        foreach ([CustomerCreditLimit::class, CustomerMerge::class] as $class) {
            $document = $class::query()->find($customer->id);
            if ($document !== null && $this->engine->withdraw($document) !== null) {
                $this->audit->record('customer.approval_withdrawn', $customer, null, [
                    'document_type' => $document->approvalDocumentType(),
                    'reason' => $reason,
                ]);
                $count++;
            }
        }

        return $count;
    }
}
