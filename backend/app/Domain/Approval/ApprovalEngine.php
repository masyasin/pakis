<?php

namespace App\Domain\Approval;

use App\Domain\Approval\Contracts\Approvable;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Enums\StepStatus;
use App\Domain\Approval\Events\ApprovalDecided;
use App\Domain\Approval\Events\ApprovalRequested;
use App\Domain\Approval\Events\ApprovalStepActivated;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalRule;
use App\Domain\Approval\Models\ApprovalRuleStep;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Domain\Approval\Support\ApprovalNotifier;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Domain\Approval\Support\Rupiah;
use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\DecimalString;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Mesin approval generik (ADR-105, BR-153, SEC-101). Semua perubahan status permintaan lewat sini.
 *
 * - `request`: pilih aturan (RuleMatcher), salin aturan ke `rule_snapshot`, aktifkan langkah 1.
 * - `decide`: kunci baris `approval_requests` (`lockForUpdate`), periksa ulang di bawah kunci: status,
 *   giliran langkah, maker-checker (termasuk Owner & delegasi), satu orang satu langkah, konfirmasi
 *   password untuk nominal besar; lalu majukan langkah dan panggil callback dokumen di transaksi yang
 *   sama. Dua keputusan bersamaan: yang kedua melihat status/langkah sudah berubah → 409.
 * - `cancel`: oleh pengaju selama belum ada keputusan (BR-153(9)).
 * - `escalateDue`: job EscalateApprovals (BR-153(6)), hanya menambah penyetuju.
 *
 * Event & notifikasi dikirim SETELAH commit, agar penerima tidak pernah mendapat notifikasi untuk
 * keputusan yang ternyata dibatalkan transaksinya.
 */
final class ApprovalEngine
{
    public function __construct(
        private readonly RuleMatcher $matcher,
        private readonly ApproverResolver $resolver,
        private readonly ApprovalVisibility $visibility,
        private readonly ApprovalNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Ajukan dokumen. Null = tidak ada aturan cocok dan `approval.auto_approve_when_no_rule` aktif:
     * dokumen langsung disetujui (`onApproved(null)`) tanpa baris permintaan.
     */
    public function request(Approvable $document, User $requester): ?ApprovalRequest
    {
        if (! $document instanceof Model) {
            throw new LogicException('Approvable harus model Eloquent.');
        }
        $type = $document->approvalDocumentType();
        if (! ApprovableRegistry::has($type)) {
            throw new LogicException("Jenis dokumen approval [{$type}] belum terdaftar di config pakis.approvables.");
        }

        /** @var list<object> $events */
        $events = [];

        try {
            $request = DB::transaction(function () use ($document, $requester, $type, &$events) {
                $pending = ApprovalRequest::query()
                    ->where('document_type', $type)
                    ->where('document_id', $document->getKey())
                    ->where('status', ApprovalStatus::Pending)
                    ->lockForUpdate()
                    ->exists();
                if ($pending) {
                    throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING);
                }

                $amount = $document->approvalAmount();
                $amount = $amount === null ? null : DecimalString::normalize($amount, 2);
                $rule = $this->matcher->match($type, $amount, $document->approvalConditions());

                if ($rule === null) {
                    if (! (bool) setting('approval.auto_approve_when_no_rule')) {
                        throw new ApiError(ErrorCode::APPROVAL_RULE_MISSING, ['document_type' => $type]);
                    }
                    $document->onApproved(null);
                    $this->audit->record('approval_request.auto_approved', $document, null, [
                        'document_type' => $type,
                        'amount' => $amount,
                    ]);

                    return null;
                }

                $request = ApprovalRequest::query()->create([
                    'document_type' => $type,
                    'document_id' => (int) $document->getKey(),
                    'approval_rule_id' => $rule->id,
                    'rule_snapshot' => self::snapshot($rule),
                    'amount' => $amount,
                    'summary' => mb_substr($document->approvalSummary(), 0, 255),
                    'context' => $document->approvalContext() ?: null,
                    'requester_user_id' => $requester->id,
                    'document_creator_user_id' => $document->creatorUserId(),
                    'scope_location_id' => $document->approvalScopeLocationId(),
                    'scope_employee_id' => $document->approvalScopeEmployeeId(),
                    'status' => ApprovalStatus::Pending,
                    'current_step' => (int) $rule->steps->first()?->step_order,
                ]);

                foreach ($rule->steps as $ruleStep) {
                    $request->steps()->create([
                        'step_order' => $ruleStep->step_order,
                        'approver_type' => $ruleStep->approver_type,
                        'permission_name' => $ruleStep->permission_name,
                        'status' => StepStatus::Waiting,
                    ]);
                }
                $request->load('steps');

                $this->audit->record('approval_request.requested', $request, null, [
                    'document_type' => $type,
                    'document_id' => $request->document_id,
                    'rule' => $rule->name,
                    'amount' => $amount,
                    'steps' => $rule->steps->count(),
                ]);
                $events[] = new ApprovalRequested($request);

                $first = $request->currentStep() ?? throw new LogicException('Aturan tanpa langkah.');
                $this->activate($request, $first, $events);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua pengajuan bersamaan: `apr_one_pending_unique` menolak yang kedua.
            throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING);
        }

        $this->dispatch($events);

        return $request;
    }

    /**
     * Setujui/tolak langkah `$stepOrder` (langkah yang dilihat pengguna saat menekan tombol). Langkah
     * lain = keputusan basi → 409 `STATE_CONFLICT`, sehingga satu klik tidak pernah memutuskan
     * langkah berikutnya.
     */
    public function decide(User $actor, int $requestId, int $stepOrder, bool $approve, ?string $note, bool $passwordConfirmed): ApprovalRequest
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);
        /** @var list<object> $events */
        $events = [];

        $request = DB::transaction(function () use ($actor, $requestId, $stepOrder, $approve, $note, $passwordConfirmed, &$events) {
            $request = ApprovalRequest::query()->lockForUpdate()->find($requestId);
            if ($request === null) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            $request->load('steps');
            if (! $this->visibility->canView($actor, $request)) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            if ($request->status !== ApprovalStatus::Pending || $request->current_step !== $stepOrder) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $request->status->value, 'current_step' => $request->current_step],
                    'Permintaan ini sudah diputuskan atau sudah pindah langkah. Muat ulang halaman.');
            }

            $step = $request->currentStep() ?? throw new LogicException('Langkah aktif tidak ditemukan.');
            $right = $this->resolver->rightOf($actor, $request, $step);
            if ($right->selfDecision) {
                throw new ApiError(ErrorCode::APPROVAL_SELF_DECISION);
            }
            if (! $right->allowed) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['current_step' => $request->current_step],
                    'Anda bukan penyetuju langkah ini.');
            }

            if ($request->amount !== null && ! $passwordConfirmed
                && DecimalString::compare($request->amount, (string) setting('approval.reauth_amount')) >= 0) {
                throw ValidationException::withMessages([
                    'current_password' => 'Konfirmasi password Anda untuk memutuskan dokumen senilai '.Rupiah::format($request->amount).'.',
                ]);
            }
            if (! $approve && ($note === null || mb_strlen($note) < 5)) {
                throw ValidationException::withMessages(['note' => 'Alasan penolakan minimal 5 karakter.']);
            }

            $step->forceFill([
                'status' => $approve ? StepStatus::Approved : StepStatus::Rejected,
                'decided_by' => $actor->id,
                'acting_for_user_id' => $right->actingForUserId,
                'decided_at' => now(),
                'note' => $note === null ? null : mb_substr($note, 0, 500),
            ])->save();

            $next = $approve
                ? $request->steps->filter(fn (ApprovalStep $s) => $s->step_order > $step->step_order)->sortBy('step_order')->first()
                : null;

            if ($next !== null) {
                $request->forceFill(['current_step' => $next->step_order])->save();
            } else {
                $request->forceFill([
                    'status' => $approve ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
                    'decided_at' => now(),
                ])->save();
                $this->skipRemaining($request);
            }

            $this->audit->record($approve ? 'approval_request.approved' : 'approval_request.rejected', $request,
                ['status' => ApprovalStatus::Pending->value, 'current_step' => $step->step_order],
                [
                    'status' => $request->status->value,
                    'current_step' => $request->current_step,
                    'decided_step' => $step->step_order,
                    'acting_for_user_id' => $right->actingForUserId,
                    'note' => $step->note,
                ],
            );

            if ($next !== null) {
                $this->activate($request, $next, $events);
            } else {
                $document = $this->lockDocument($request);
                $approve ? $document->onApproved($request) : $document->onRejected($request);
                $events[] = new ApprovalDecided($request);
            }

            return $request;
        });

        $this->dispatch($events);

        return $request;
    }

    /**
     * Batalkan permintaan `pending` (diperiksa ulang di bawah kunci):
     * - pengaju, selama belum ada langkah yang diputuskan (BR-153(9)); alasan opsional;
     * - pemegang `approvals.configure` (bukan Super Admin), kapan pun selama `pending`, alasan wajib
     *   5–500 (PA-38: jalan keluar langkah buntu). Dicatat sebagai pembatalan admin.
     */
    public function cancel(User $actor, int $requestId, ?string $note = null): ApprovalRequest
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        return DB::transaction(function () use ($actor, $requestId, $note) {
            $request = ApprovalRequest::query()->lockForUpdate()->find($requestId);
            if ($request === null) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            $request->load('steps');
            if (! $this->visibility->canView($actor, $request)) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            if ($request->status !== ApprovalStatus::Pending) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $request->status->value],
                    'Permintaan ini sudah tidak menunggu keputusan.');
            }

            $mode = $this->visibility->cancelMode($actor, $request);
            if ($mode === null) {
                if ($request->requester_user_id === $actor->id) {
                    throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $request->status->value],
                        'Permintaan yang sudah diputuskan (sebagian) tidak bisa dibatalkan.');
                }
                throw new ApiError(ErrorCode::FORBIDDEN);
            }
            if ($mode === ApprovalVisibility::CANCEL_ADMIN && ($note === null || mb_strlen($note) < 5)) {
                throw ValidationException::withMessages(['note' => 'Alasan pembatalan minimal 5 karakter.']);
            }

            $this->close($request, ApprovalStatus::Cancelled, $mode === ApprovalVisibility::CANCEL_ADMIN ? 'admin' : null,
                $note === null ? null : mb_substr($note, 0, 500));
            $this->lockDocument($request)->onCancelled($request);

            return $request;
        });
    }

    /**
     * Dokumen ditarik kembali/berubah setelah diajukan → permintaan aktifnya batal otomatis
     * (BR-153(10)). Dipanggil Action dokumen di dalam transaksinya sendiri; callback dokumen TIDAK
     * dipanggil karena dokumenlah yang memulai. Null bila tidak ada permintaan aktif.
     */
    public function withdraw(Approvable $document): ?ApprovalRequest
    {
        if (! $document instanceof Model) {
            throw new LogicException('Approvable harus model Eloquent.');
        }

        return DB::transaction(function () use ($document) {
            $request = ApprovalRequest::query()
                ->where('document_type', $document->approvalDocumentType())
                ->where('document_id', $document->getKey())
                ->where('status', ApprovalStatus::Pending)
                ->lockForUpdate()
                ->first();
            if ($request === null) {
                return null;
            }
            $request->load('steps');
            $this->close($request, ApprovalStatus::Cancelled, 'withdrawn');

            return $request;
        });
    }

    /**
     * Tandai langkah yang lewat `escalate_at` sebagai tereskalasi (BR-153(6), jam kalender: tidak
     * melihat jam kerja/libur). Idempoten: langkah yang sudah `escalated` tidak disentuh lagi.
     * Tidak pernah mengubah status langkah/permintaan.
     *
     * @return int jumlah langkah yang baru tereskalasi
     */
    public function escalateDue(): int
    {
        $due = ApprovalStep::query()
            ->where('status', StepStatus::Pending)
            ->where('escalated', false)
            ->whereNotNull('escalate_at')
            ->where('escalate_at', '<=', now())
            ->orderBy('id')
            ->pluck('approval_request_id')
            ->unique();

        $count = 0;
        foreach ($due as $requestId) {
            $escalated = DB::transaction(function () use ($requestId) {
                $request = ApprovalRequest::query()->lockForUpdate()->find((int) $requestId);
                if ($request === null || $request->status !== ApprovalStatus::Pending) {
                    return null;
                }
                $request->load('steps');
                $step = $request->currentStep();
                if ($step === null || $step->status !== StepStatus::Pending || $step->escalated
                    || $step->escalate_at === null || $step->escalate_at->isFuture()) {
                    return null;
                }

                $step->forceFill(['escalated' => true])->save();
                $this->audit->record('approval_request.escalated', $request, ['escalated' => false], [
                    'escalated' => true,
                    'step' => $step->step_order,
                    'reason' => 'timeout',
                ], 'sistem');

                return [$request, $step];
            });

            if ($escalated !== null) {
                $count++;
                $this->notifier->escalated($escalated[0], $escalated[1]);
            }
        }

        return $count;
    }

    /**
     * Langkah menjadi aktif: tentukan penyetuju (atasan/kepala), batas eskalasi (jam kalender), dan
     * langsung tereskalasi bila tidak ada calon sama sekali (BR-153(4)(5)(11), PA-28).
     *
     * @param  list<object>  $events
     */
    private function activate(ApprovalRequest $request, ApprovalStep $step, array &$events): void
    {
        $hours = $request->snapshotStep($step->step_order)['escalate_after_hours'] ?? null;
        $hours = $hours === null ? (int) setting('approval.default_escalation_hours') : (int) $hours;
        $now = now();

        $step->forceFill([
            'status' => StepStatus::Pending,
            'activated_at' => $now,
            'escalate_at' => $now->addHours($hours),
            'resolved_employee_id' => $this->resolver->resolveEmployee($request, $step->approver_type),
        ])->save();

        if ($this->resolver->directCandidates($request, $step)->isEmpty()) {
            $step->forceFill(['escalated' => true])->save();
            $this->audit->record('approval_request.escalated', $request, ['escalated' => false], [
                'escalated' => true,
                'step' => $step->step_order,
                'reason' => 'no_candidate',
            ]);
        }

        $events[] = new ApprovalStepActivated($request, $step);
    }

    private function close(ApprovalRequest $request, ApprovalStatus $status, ?string $reason = null, ?string $note = null): void
    {
        $request->forceFill(['status' => $status, 'decided_at' => now()])->save();
        $this->skipRemaining($request);
        $this->audit->record('approval_request.'.$status->value, $request, ['status' => ApprovalStatus::Pending->value], array_filter([
            'status' => $status->value,
            'reason' => $reason,
            'note' => $note,
        ]));
    }

    private function skipRemaining(ApprovalRequest $request): void
    {
        foreach ($request->steps as $step) {
            if ($step->status === StepStatus::Waiting || $step->status === StepStatus::Pending) {
                $step->forceFill(['status' => StepStatus::Skipped])->save();
            }
        }
    }

    private function lockDocument(ApprovalRequest $request): Approvable
    {
        return ApprovableRegistry::find($request->document_type, $request->document_id, lock: true)
            ?? throw new LogicException("Dokumen {$request->document_type}#{$request->document_id} tidak ditemukan.");
    }

    /**
     * @return array{id: int, name: string, document_type: string, min_amount: string|null, max_amount: string|null, conditions: array<string, mixed>|null, priority: int, steps: list<array{step_order: int, approver_type: string, permission_name: string|null, escalate_after_hours: int|null, escalation_permission: string|null}>}
     */
    public static function snapshot(ApprovalRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'document_type' => $rule->document_type,
            'min_amount' => $rule->min_amount,
            'max_amount' => $rule->max_amount,
            'conditions' => $rule->conditions,
            'priority' => $rule->priority,
            'steps' => array_values($rule->steps->map(fn (ApprovalRuleStep $s) => $s->snapshot())->all()),
        ];
    }

    /** @param  list<object>  $events */
    private function dispatch(array $events): void
    {
        foreach ($events as $event) {
            event($event);
        }
    }
}
