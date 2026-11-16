<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hapus aturan (15 §5 `destroy`): aturan yang pernah dipakai permintaan hanya dinonaktifkan (riwayat
 * & FK `apr_rule_fk` tetap utuh); yang belum pernah dipakai dihapus beserta langkahnya.
 */
final class DeactivateApprovalRule
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return 'deactivated'|'deleted' */
    public function handle(ApprovalRule $rule, int $actorId): string
    {
        return DB::transaction(function () use ($rule, $actorId) {
            $rule->load('steps');
            $before = [...SaveApprovalRule::values($rule), 'steps' => SaveApprovalRule::stepValues($rule)];

            if ($rule->isUsed()) {
                $rule->forceFill(['is_active' => false, 'updated_by' => $actorId])->save();
                $this->audit->record('approval_rule.deactivated', $rule, ['is_active' => $before['is_active']], ['is_active' => false]);

                return 'deactivated';
            }

            $this->audit->record('approval_rule.deleted', $rule, $before, null);
            $rule->delete();

            return 'deleted';
        });
    }
}
