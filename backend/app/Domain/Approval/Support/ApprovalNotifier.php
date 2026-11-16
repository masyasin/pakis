<?php

namespace App\Domain\Approval\Support;

use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Models\ApprovalStep;
use App\Domain\Approval\Notifications\ApprovalDecidedNotification;
use App\Domain\Approval\Notifications\ApprovalEscalatedNotification;
use App\Domain\Approval\Notifications\ApprovalRequestedNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * Penerima notifikasi approval (15 §9.1). Lonceng selalu; kanal luar per `notification.on_approval_*`
 * (diatur PakisNotification/NotificationRouter).
 */
final class ApprovalNotifier
{
    public function __construct(private readonly ApproverResolver $resolver) {}

    /** Langkah aktif → calon penyetuju + delegasi. Langkah yang langsung tereskalasi = jenis `approval_escalated`. */
    public function stepActivated(ApprovalRequest $request, ApprovalStep $step): void
    {
        if ($step->escalated) {
            $this->escalated($request, $step);

            return;
        }

        $recipients = $this->resolver->candidatesWithDelegates($request, $step);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ApprovalRequestedNotification(
                $request->id, ApprovableRegistry::label($request->document_type), $request->summary,
            ));
        }
    }

    /** Penyetuju langkah + pemegang izin eskalasi (semuanya calon setelah `escalated=true`). */
    public function escalated(ApprovalRequest $request, ApprovalStep $step): void
    {
        $recipients = $this->resolver->candidatesWithDelegates($request, $step);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new ApprovalEscalatedNotification(
                $request->id, ApprovableRegistry::label($request->document_type), $request->summary,
            ));
        }
    }

    public function decided(ApprovalRequest $request): void
    {
        $requester = User::query()->whereKey($request->requester_user_id)->where('is_active', true)->first();
        if ($requester === null) {
            return;
        }

        $last = $request->steps->filter(fn (ApprovalStep $s) => $s->status->isDecided())->sortByDesc('step_order')->first();
        Notification::send($requester, new ApprovalDecidedNotification(
            $request->id,
            ApprovableRegistry::label($request->document_type),
            $request->summary,
            $request->status === ApprovalStatus::Approved ? ApprovalStatus::Approved : ApprovalStatus::Rejected,
            $request->status === ApprovalStatus::Rejected ? $last?->note : null,
        ));
    }
}
