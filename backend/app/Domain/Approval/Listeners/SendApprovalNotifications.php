<?php

namespace App\Domain\Approval\Listeners;

use App\Domain\Approval\Events\ApprovalDecided;
use App\Domain\Approval\Events\ApprovalStepActivated;
use App\Domain\Approval\Support\ApprovalNotifier;

/** Event mesin approval → notifikasi in-app (15 §9.1, §11.2). Event dipancarkan setelah commit. */
final class SendApprovalNotifications
{
    public function __construct(private readonly ApprovalNotifier $notifier) {}

    public function stepActivated(ApprovalStepActivated $event): void
    {
        $this->notifier->stepActivated($event->request, $event->step);
    }

    public function decided(ApprovalDecided $event): void
    {
        $this->notifier->decided($event->request);
    }
}
