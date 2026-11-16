<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Enums\ApproverType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu langkah aturan (15 §4.2). `escalate_after_hours` NULL = setting
 * `approval.default_escalation_hours`; `escalation_permission` NULL = pemegang `approvals.configure`
 * saat tereskalasi (PA-28).
 *
 * @property int $id
 * @property int $approval_rule_id
 * @property int $step_order
 * @property ApproverType $approver_type
 * @property string|null $permission_name
 * @property int|null $escalate_after_hours
 * @property string|null $escalation_permission
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ApprovalRuleStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'approver_type' => ApproverType::class,
            'step_order' => 'integer',
            'escalate_after_hours' => 'integer',
        ];
    }

    /** @return BelongsTo<ApprovalRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'approval_rule_id');
    }

    /** @return array{step_order: int, approver_type: string, permission_name: string|null, escalate_after_hours: int|null, escalation_permission: string|null} */
    public function snapshot(): array
    {
        return [
            'step_order' => $this->step_order,
            'approver_type' => $this->approver_type->value,
            'permission_name' => $this->permission_name,
            'escalate_after_hours' => $this->escalate_after_hours,
            'escalation_permission' => $this->escalation_permission,
        ];
    }
}
