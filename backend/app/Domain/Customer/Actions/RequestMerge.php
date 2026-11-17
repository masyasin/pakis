<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Approvables\CustomerMerge;
use App\Domain\Customer\Models\Customer;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Ajukan gabung kios duplikat (15 §5 `admin.customers.merge`, BR-166(5)) → approval
 * `customer_merge`. Kios ini = SUMBER (akan `merged`), `$target` = kios yang dipertahankan. Kedua
 * kios wajib dalam scope pengaju (dicek controller/request; di luar scope = "tidak ditemukan").
 */
final class RequestMerge
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Customer $source, Customer $target, string $reason, User $actor): ?ApprovalRequest
    {
        return DB::transaction(function () use ($source, $target, $reason, $actor) {
            $ids = [$source->id, $target->id];
            sort($ids);
            Customer::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $document = CustomerMerge::query()->findOrFail($source->id);
            $lockedTarget = Customer::query()->find($target->id);
            CustomerMerge::assertMergeable($document, $lockedTarget);
            if (self::pending($document->id) !== null) {
                throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING, publicMessage: 'Kios ini sudah punya permintaan gabung yang menunggu persetujuan.');
            }
            /** @var Customer $lockedTarget */
            $document->mergeTarget = $lockedTarget;
            $document->changeReason = $reason;

            $this->audit->record('customer.merge_requested', $document, null, [
                'merge_into_id' => $lockedTarget->id,
                'merge_into_code' => $lockedTarget->code,
                'reason' => $reason,
            ]);

            return $this->engine->request($document, $actor);
        });
    }

    public static function pending(int $customerId): ?ApprovalRequest
    {
        return ApprovalRequest::query()->where('document_type', CustomerMerge::DOCUMENT_TYPE)
            ->where('document_id', $customerId)->where('status', ApprovalStatus::Pending)->first();
    }
}
