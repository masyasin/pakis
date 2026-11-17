<?php

namespace App\Domain\Approval\Support;

use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Enums\StepStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Settings\DecimalString;
use App\Models\User;

/**
 * Bentuk prop Inertia permintaan approval untuk kotak masuk & detail (kontrak untuk halaman
 * `approvals/index`/`approvals/show`, dicatat di laporan T-067). Hanya kolom yang perlu tampil; tidak
 * ada `rule_snapshot` mentah. Waktu dikirim ISO-8601 UTC; halaman memformat ke zona tampilan.
 */
final class ApprovalPresenter
{
    public function __construct(
        private readonly ApproverResolver $resolver,
        private readonly ApprovalVisibility $visibility,
    ) {}

    public static function needsPassword(ApprovalRequest $request, bool $passwordRecent): bool
    {
        return ! $passwordRecent && $request->amount !== null
            && DecimalString::compare($request->amount, (string) setting('approval.reauth_amount')) >= 0;
    }

    /** @return array<string, mixed> */
    public function row(ApprovalRequest $request, User $user, bool $passwordRecent): array
    {
        $step = $request->currentStep();
        $canDecide = $this->visibility->canDecideNow($user, $request);
        $cancelMode = $this->visibility->cancelMode($user, $request);

        return [
            'id' => $request->id,
            'document_type' => $request->document_type,
            'document_type_label' => ApprovableRegistry::label($request->document_type),
            'summary' => $request->summary,
            'amount' => $request->amount,
            'amount_label' => Rupiah::format($request->amount),
            'reason' => Rupiah::reason($request),
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'current_step' => $request->current_step,
            'steps_total' => $request->steps->count(),
            'requester' => $request->requester === null ? null : ['id' => $request->requester->id, 'name' => $request->requester->name],
            'created_at' => $request->created_at?->toIso8601String(),
            'waiting_since' => $request->status === ApprovalStatus::Pending ? $step?->activated_at?->toIso8601String() : null,
            'escalated' => $request->status === ApprovalStatus::Pending && (bool) $step?->escalated,
            'requires_password' => $canDecide && self::needsPassword($request, $passwordRecent),
            'can' => [
                'decide' => $canDecide,
                'cancel' => $cancelMode !== null,
            ],
            'cancel_requires_note' => $cancelMode === ApprovalVisibility::CANCEL_ADMIN,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ApprovalRequest $request, User $user, bool $passwordRecent): array
    {
        $current = $request->currentStep();
        $right = $current !== null && $request->status === ApprovalStatus::Pending
            ? $this->resolver->rightOf($user, $request, $current)
            : null;
        $actingFor = $right?->actingForUserId === null ? null : User::query()->withTrashed()->find($right->actingForUserId);
        $url = null;
        $document = ApprovableRegistry::find($request->document_type, $request->document_id);
        if ($document !== null) {
            $url = $document->approvalUrl();
        }

        return [
            ...$this->row($request, $user, $passwordRecent),
            'context' => self::visibleContext($request->context),
            'document_url' => $url,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'rule' => ['name' => $request->rule_snapshot['name']],
            'acting_for' => $actingFor === null ? null : ['id' => $actingFor->id, 'name' => $actingFor->name],
            'steps' => $request->steps->map(fn (ApprovalStep $s) => $this->step($request, $s))->values()->all(),
        ];
    }

    /**
     * Konteks untuk penyetuju tanpa kunci mesin berawalan `_` (mis. `_merge_into_id`): kunci itu
     * dibaca callback dokumen saat diputuskan, bukan informasi tampilan (PA-56).
     *
     * @param  array<array-key, mixed>|null  $context
     * @return array<array-key, mixed>
     */
    public static function visibleContext(?array $context): array
    {
        return array_filter($context ?? [], fn ($key) => ! is_string($key) || ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, mixed> */
    private function step(ApprovalRequest $request, ApprovalStep $step): array
    {
        $snapshot = $request->snapshotStep($step->step_order) ?? [];
        $waitingFor = $step->status === StepStatus::Pending
            ? $this->resolver->candidatesWithDelegates($request, $step)->take(10)->map(fn (User $u) => $u->name)->values()->all()
            : [];

        return [
            'step_order' => $step->step_order,
            'approver_type' => $step->approver_type->value,
            'approver_label' => StepDescriber::approver($step->approver_type, $step->permission_name),
            'escalation_label' => StepDescriber::escalation($snapshot['escalation_permission'] ?? null),
            'status' => $step->status->value,
            'status_label' => $step->status->label(),
            'activated_at' => $step->activated_at?->toIso8601String(),
            'escalate_at' => $step->escalate_at?->toIso8601String(),
            'escalated' => $step->escalated,
            'decided_by' => $step->decider === null ? null : ['id' => $step->decider->id, 'name' => $step->decider->name],
            'acting_for' => $step->actingFor === null ? null : ['id' => $step->actingFor->id, 'name' => $step->actingFor->name],
            'decided_at' => $step->decided_at?->toIso8601String(),
            'note' => $step->note,
            'waiting_for' => $waitingFor,
        ];
    }
}
