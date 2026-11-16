<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Models\ApprovalDelegation;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Cabut delegasi (`is_active=false`, riwayat tetap ada; SEC-110). Wewenang di ApprovalDelegationPolicy. */
final class RevokeDelegation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ApprovalDelegation $delegation, int $actorId): ApprovalDelegation
    {
        return DB::transaction(function () use ($delegation, $actorId) {
            $delegation = ApprovalDelegation::query()->lockForUpdate()->findOrFail($delegation->id);
            if (! $delegation->is_active) {
                return $delegation;
            }
            $delegation->forceFill(['is_active' => false, 'updated_by' => $actorId])->save();
            $this->audit->record('approval_delegation.revoked', $delegation, ['is_active' => true], ['is_active' => false]);

            return $delegation;
        });
    }
}
