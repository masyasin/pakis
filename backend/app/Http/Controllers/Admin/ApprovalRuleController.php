<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Approval\Actions\DeactivateApprovalRule;
use App\Domain\Approval\Actions\PreviewApprovalChain;
use App\Domain\Approval\Actions\SaveApprovalRule;
use App\Domain\Approval\Enums\ApproverType;
use App\Domain\Approval\Listings\ApprovalRuleListing;
use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Approval\Models\ApprovalRuleStep;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Approval\Support\StepDescriber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approval\ApprovalRuleRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Aturan Persetujuan (15 §5, FR-A02, SEC-110). Izin `approvals.configure` di route; buat/ubah/hapus
 * butuh konfirmasi password (middleware RequirePassword, rute statis). Pratinjau JSON tanpa
 * konfirmasi karena hanya membaca.
 */
class ApprovalRuleController extends Controller
{
    #[UsesListing(ApprovalRuleListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(ApprovalRule::query()->with('steps')->withExists('requests as used'), new ApprovalRuleListing, $request)
            ->through(fn (ApprovalRule $rule) => [
                'id' => $rule->id,
                'document_type' => $rule->document_type,
                'document_type_label' => ApprovableRegistry::label($rule->document_type),
                'name' => $rule->name,
                'min_amount' => $rule->min_amount,
                'max_amount' => $rule->max_amount,
                'min_amount_label' => Rupiah::format($rule->min_amount),
                'max_amount_label' => Rupiah::format($rule->max_amount),
                'conditions' => $rule->conditions,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'used' => (bool) $rule->getAttribute('used'),
                'steps' => $rule->steps->map(fn (ApprovalRuleStep $s) => [
                    'step_order' => $s->step_order,
                    'label' => StepDescriber::approver($s->approver_type, $s->permission_name),
                ])->values()->all(),
            ]);

        return Inertia::render('site-settings/approval-rules/index', [
            ...$result->toInertia(),
            'documentTypes' => self::documentTypes(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('site-settings/approval-rules/form', [...self::formOptions(), 'rule' => null]);
    }

    public function store(ApprovalRuleRequest $request, SaveApprovalRule $action): RedirectResponse
    {
        $rule = $action->handle(null, $request->ruleData(), $request->steps(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Aturan \"{$rule->name}\" dibuat."]);

        return to_route('admin.approval-rules.index');
    }

    public function edit(ApprovalRule $rule): Response
    {
        $rule->load('steps');

        return Inertia::render('site-settings/approval-rules/form', [
            ...self::formOptions(),
            'rule' => [
                'id' => $rule->id,
                'document_type' => $rule->document_type,
                'name' => $rule->name,
                'min_amount' => $rule->min_amount,
                'max_amount' => $rule->max_amount,
                'conditions' => $rule->conditions,
                'priority' => $rule->priority,
                'is_active' => $rule->is_active,
                'used' => $rule->isUsed(),
                'steps' => $rule->steps->map(fn (ApprovalRuleStep $s) => [
                    'approver_type' => $s->approver_type->value,
                    'permission_name' => $s->permission_name,
                    'escalate_after_hours' => $s->escalate_after_hours,
                    'escalation_permission' => $s->escalation_permission,
                ])->values()->all(),
            ],
        ]);
    }

    public function update(ApprovalRuleRequest $request, ApprovalRule $rule, SaveApprovalRule $action): RedirectResponse
    {
        $rule = $action->handle($rule, $request->ruleData(), $request->steps(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Aturan \"{$rule->name}\" disimpan. Permintaan yang sedang berjalan tetap memakai aturan lama."]);

        return to_route('admin.approval-rules.index');
    }

    public function destroy(Request $request, ApprovalRule $rule, DeactivateApprovalRule $action): RedirectResponse
    {
        $result = $action->handle($rule, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => $result === 'deleted'
            ? "Aturan \"{$rule->name}\" dihapus."
            : "Aturan \"{$rule->name}\" sudah pernah dipakai, jadi dinonaktifkan (tidak dihapus)."]);

        return to_route('admin.approval-rules.index');
    }

    /** Rantai penyetuju untuk jenis dokumen + nominal (JSON, 05 §2.2 untuk galat). */
    public function preview(Request $request, PreviewApprovalChain $action): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in(ApprovableRegistry::types())],
            'amount' => ['nullable', 'string', 'regex:'.ApprovalRuleRequest::MONEY],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['nullable', 'scalar'],
        ]);
        /** @var array<string, int|string|null> $conditions */
        $conditions = $data['conditions'] ?? [];

        return response()->json(['data' => $action->handle((string) $data['document_type'], $data['amount'] ?? null, $conditions)]);
    }

    /** @return list<array{value: string, label: string, conditions: list<string>}> */
    private static function documentTypes(): array
    {
        return ApprovableRegistry::options();
    }

    /** @return array<string, mixed> */
    private static function formOptions(): array
    {
        return [
            'documentTypes' => self::documentTypes(),
            'approverTypes' => ApproverType::options(),
            'permissions' => PermissionCatalog::grouped(),
            'defaultEscalationHours' => (int) setting('approval.default_escalation_hours'),
            'maxSteps' => ApprovalRuleRequest::MAX_STEPS,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
