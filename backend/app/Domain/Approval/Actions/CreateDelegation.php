<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Audit\AuditLogger;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Buat delegasi bertanggal (BR-153(7), SEC-101, SEC-110). Validasi isian di ApprovalDelegationRequest;
 * di sini aturan yang tidak boleh lolos walau validasi form dilewati: tidak ke diri sendiri (juga
 * CHECK `apd_not_self_chk`). Rantai delegasi tidak dicegah saat dibuat, tetapi tidak pernah memberi
 * hak (ApproverResolver hanya meneruskan hak LANGSUNG pemberi).
 */
final class CreateDelegation
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{delegator_user_id: int, delegate_user_id: int, start_date: string, end_date: string, document_types: list<string>|null, reason: string}  $data
     */
    public function handle(array $data, int $actorId): ApprovalDelegation
    {
        if ($data['delegator_user_id'] === $data['delegate_user_id']) {
            throw new ApiError(ErrorCode::VALIDATION_FAILED, ['delegate_user_id' => 'Delegasi tidak boleh ke diri sendiri.']);
        }

        return DB::transaction(function () use ($data, $actorId) {
            $delegation = ApprovalDelegation::query()->create([
                ...$data,
                'is_active' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->audit->record('approval_delegation.created', $delegation, null, $delegation->only(ApprovalDelegation::AUDITED));

            return $delegation;
        });
    }
}
