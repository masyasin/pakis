<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Approvables\CustomerCreditLimit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\DecimalString;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Ajukan limit kredit kios (15 §5 `admin.customers.credit-limit`, BR-125, SEC-110) → approval
 * `customer_credit_limit` bertingkat (PA-51). Kios belum terverifikasi → 422 `CUSTOMER_NOT_VERIFIED`
 * (lapis Action; Policy `setCreditLimit` menolak hal yang sama, CHECK DB lapis ketiga).
 *
 * Mengembalikan permintaan approval, atau null bila langsung disetujui (tanpa aturan +
 * `approval.auto_approve_when_no_rule`).
 */
final class RequestCreditLimit
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Customer $customer, string $limit, string $reason, User $actor): ?ApprovalRequest
    {
        $limit = DecimalString::normalize($limit, 2);

        return DB::transaction(function () use ($customer, $limit, $reason, $actor) {
            $document = CustomerCreditLimit::query()->lockForUpdate()->findOrFail($customer->id);
            CustomerCreditLimit::assertEligible($document);
            if (DecimalString::compare($limit, $document->credit_limit) === 0) {
                $message = 'Limit baru sama dengan limit sekarang.';
                throw new ApiError(ErrorCode::VALIDATION_FAILED, ['credit_limit' => $message], $message);
            }
            if (self::pending($document->id) !== null) {
                throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING, publicMessage: 'Kios ini sudah punya perubahan limit yang menunggu persetujuan.');
            }

            $document->requestedLimit = $limit;
            $document->changeReason = $reason;

            $this->audit->record('customer.credit_limit_requested', $document, ['credit_limit' => $document->credit_limit], [
                'credit_limit' => $limit,
                'reason' => $reason,
            ]);

            return $this->engine->request($document, $actor);
        });
    }

    public static function pending(int $customerId): ?ApprovalRequest
    {
        return ApprovalRequest::query()->where('document_type', CustomerCreditLimit::DOCUMENT_TYPE)
            ->where('document_id', $customerId)->where('status', ApprovalStatus::Pending)->first();
    }
}
