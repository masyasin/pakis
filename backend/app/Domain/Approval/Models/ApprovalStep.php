<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Enums\ApproverType;
use App\Domain\Approval\Enums\StepStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Langkah sebuah permintaan (15 §4.2). Pengaturan eskalasi langkah dibaca dari `rule_snapshot`
 * permintaannya (snapshot BR-153(2)), bukan dari aturan yang mungkin sudah diedit.
 *
 * @property int $id
 * @property int $approval_request_id
 * @property int $step_order
 * @property ApproverType $approver_type
 * @property string|null $permission_name
 * @property int|null $resolved_employee_id
 * @property StepStatus $status
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $escalate_at
 * @property bool $escalated
 * @property int|null $decided_by
 * @property int|null $acting_for_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ApprovalRequest $request
 * @property-read User|null $decider
 * @property-read User|null $actingFor
 */
class ApprovalStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'approver_type' => ApproverType::class,
            'status' => StepStatus::class,
            'step_order' => 'integer',
            'resolved_employee_id' => 'integer',
            'activated_at' => 'datetime',
            'escalate_at' => 'datetime',
            'escalated' => 'boolean',
            'decided_by' => 'integer',
            'acting_for_user_id' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ApprovalRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id')->withTrashed();
    }
}
