<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permintaan approval satu dokumen (15 §4.2, BR-153). `rule_snapshot` = salinan aturan & langkah
 * saat diajukan (BR-153(2)); `pending_key` (generated) menjamin satu permintaan `pending` per dokumen.
 *
 * Route `{approval}` hanya terlihat oleh calon penyetuju langkah aktif (PA-27), pengaju, pemutus
 * langkah sebelumnya, atau pemegang `approvals.view` dalam scope (ApprovalVisibility); selain itu 404.
 *
 * @property int $id
 * @property string $document_type
 * @property int $document_id
 * @property int $approval_rule_id
 * @property array{id: int, name: string, document_type: string, min_amount: string|null, max_amount: string|null, conditions: array<string, mixed>|null, priority: int, steps: list<array{step_order: int, approver_type: string, permission_name: string|null, escalate_after_hours: int|null, escalation_permission: string|null}>} $rule_snapshot
 * @property string|null $amount
 * @property string $summary
 * @property array<string, mixed>|null $context
 * @property int $requester_user_id
 * @property int|null $document_creator_user_id
 * @property int|null $scope_location_id
 * @property int|null $scope_employee_id
 * @property ApprovalStatus $status
 * @property int $current_step
 * @property CarbonImmutable|null $decided_at
 * @property string|null $pending_key
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ApprovalStep> $steps
 * @property-read User|null $requester
 * @property-read ApprovalRule|null $rule
 */
class ApprovalRequest extends Model
{
    protected $guarded = ['id', 'pending_key'];

    protected function casts(): array
    {
        return [
            'document_id' => 'integer',
            'approval_rule_id' => 'integer',
            'rule_snapshot' => 'array',
            'amount' => 'decimal:2',
            'context' => 'array',
            'requester_user_id' => 'integer',
            'document_creator_user_id' => 'integer',
            'scope_location_id' => 'integer',
            'scope_employee_id' => 'integer',
            'status' => ApprovalStatus::class,
            'current_step' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /** @return HasMany<ApprovalStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id')->withTrashed();
    }

    /** @return BelongsTo<ApprovalRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ApprovalRule::class, 'approval_rule_id');
    }

    public function currentStep(): ?ApprovalStep
    {
        return $this->steps->firstWhere('step_order', $this->current_step);
    }

    /**
     * Pengaturan langkah dari snapshot (eskalasi tidak ikut kolom `approval_steps`).
     *
     * @return array{step_order: int, approver_type: string, permission_name: string|null, escalate_after_hours: int|null, escalation_permission: string|null}|null
     */
    public function snapshotStep(int $order): ?array
    {
        foreach ($this->rule_snapshot['steps'] as $step) {
            if ((int) $step['step_order'] === $order) {
                return $step;
            }
        }

        return null;
    }

    /** Pengaju & pembuat dokumen tidak pernah memutuskan (maker-checker BR-153(4)). */
    public function isMaker(int $userId): bool
    {
        return $userId === $this->requester_user_id || $userId === $this->document_creator_user_id;
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return null;
        }

        $approval = self::query()->where($field ?? $this->getRouteKeyName(), $value)->first();

        return $approval !== null && app(ApprovalVisibility::class)->canView($user, $approval) ? $approval : null;
    }
}
