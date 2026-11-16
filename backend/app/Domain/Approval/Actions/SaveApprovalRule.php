<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Approval\Models\ApprovalRuleStep;
use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Buat/ubah aturan approval beserta langkahnya (FR-A02, SEC-110). Validasi isian di
 * ApprovalRuleRequest. Langkah diganti seluruhnya; permintaan berjalan tidak terpengaruh karena
 * memakai `rule_snapshot` (BR-153(2)). Audit menyimpan nilai lama/baru termasuk rantai langkah.
 */
final class SaveApprovalRule
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{document_type: string, name: string, min_amount: string|null, max_amount: string|null, conditions: array<string, list<int|string>>|null, priority: int, is_active: bool}  $data
     * @param  list<array{approver_type: string, permission_name: string|null, escalate_after_hours: int|null, escalation_permission: string|null}>  $steps
     */
    public function handle(?ApprovalRule $rule, array $data, array $steps, int $actorId): ApprovalRule
    {
        return DB::transaction(function () use ($rule, $data, $steps, $actorId) {
            $before = $rule === null ? null : [...self::values($rule), 'steps' => self::stepValues($rule)];

            $rule ??= new ApprovalRule(['created_by' => $actorId]);
            $rule->fill([...$data, 'updated_by' => $actorId])->save();

            $rule->steps()->delete();
            foreach ($steps as $i => $step) {
                $rule->steps()->create([
                    'step_order' => $i + 1,
                    'approver_type' => $step['approver_type'],
                    'permission_name' => $step['approver_type'] === 'permission' ? $step['permission_name'] : null,
                    'escalate_after_hours' => $step['escalate_after_hours'],
                    'escalation_permission' => $step['escalation_permission'],
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);
            }
            $rule->load('steps');

            $after = [...self::values($rule), 'steps' => self::stepValues($rule)];
            $this->audit->record($before === null ? 'approval_rule.created' : 'approval_rule.updated', $rule, $before, $after);

            return $rule;
        });
    }

    /** @return array<string, mixed> */
    public static function values(ApprovalRule $rule): array
    {
        return $rule->only(ApprovalRule::AUDITED);
    }

    /** @return list<array<string, mixed>> */
    public static function stepValues(ApprovalRule $rule): array
    {
        return array_values($rule->steps->map(fn (ApprovalRuleStep $s) => $s->snapshot())->all());
    }
}
