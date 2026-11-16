<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApproverType;
use App\Domain\Approval\Models\ApprovalRuleStep;
use App\Domain\Approval\RuleMatcher;
use App\Domain\Approval\Support\StepDescriber;
use App\Domain\Settings\DecimalString;
use App\Models\User;

/**
 * Pratinjau "siapa menyetujui dokumen senilai X" (FR-A02, `admin.approval-rules.preview`). Memakai
 * RuleMatcher yang sama dengan pengajuan sungguhan, jadi hasilnya tidak bisa berbeda. Atasan langsung
 * & kepala lokasi bergantung dokumen, jadi hanya disebut jenisnya; pemegang izin disebut namanya
 * (maks. 10) tanpa saringan scope, karena dokumen contoh tidak punya scope.
 */
final class PreviewApprovalChain
{
    public const MAX_NAMES = 10;

    public function __construct(private readonly RuleMatcher $matcher) {}

    /**
     * @param  array<string, int|string|null>  $conditions
     * @return array{rule: array{id: int, name: string, priority: int}|null, auto_approve: bool, missing: bool, steps: list<array{step_order: int, approver_type: string, approver_label: string, escalate_after_hours: int, escalation_label: string, holders: list<string>, holders_count: int|null}>}
     */
    public function handle(string $documentType, ?string $amount, array $conditions = []): array
    {
        $amount = $amount === null || $amount === '' ? null : DecimalString::normalize($amount, 2);
        $rule = $this->matcher->match($documentType, $amount, $conditions);

        if ($rule === null) {
            $auto = (bool) setting('approval.auto_approve_when_no_rule');

            return ['rule' => null, 'auto_approve' => $auto, 'missing' => ! $auto, 'steps' => []];
        }

        $default = (int) setting('approval.default_escalation_hours');

        return [
            'rule' => ['id' => $rule->id, 'name' => $rule->name, 'priority' => $rule->priority],
            'auto_approve' => false,
            'missing' => false,
            'steps' => array_values($rule->steps->map(function (ApprovalRuleStep $step) use ($default) {
                $holders = $step->approver_type === ApproverType::Permission && $step->permission_name !== null
                    ? ApproverResolver::holders($step->permission_name)
                    : null;

                return [
                    'step_order' => $step->step_order,
                    'approver_type' => $step->approver_type->value,
                    'approver_label' => StepDescriber::approver($step->approver_type, $step->permission_name),
                    'escalate_after_hours' => $step->escalate_after_hours ?? $default,
                    'escalation_label' => StepDescriber::escalation($step->escalation_permission),
                    'holders' => $holders === null ? [] : array_values($holders->take(self::MAX_NAMES)->map(fn (User $u) => $u->name)->all()),
                    'holders_count' => $holders?->count(),
                ];
            })->all()),
        ];
    }
}
