<?php

namespace App\Domain\Approval\Notifications;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/** Permintaan selesai diputuskan (15 §9.1 `approval_decided`) → pengaju. */
class ApprovalDecidedNotification extends PakisNotification
{
    public function __construct(
        private readonly int $approvalId,
        private readonly string $documentLabel,
        private readonly string $summary,
        private readonly ApprovalStatus $status,
        private readonly ?string $note,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ApprovalDecided;
    }

    public function title(): string
    {
        return $this->status === ApprovalStatus::Approved
            ? "{$this->documentLabel} disetujui"
            : "{$this->documentLabel} ditolak";
    }

    public function body(): string
    {
        $text = $this->summary.' '.($this->status === ApprovalStatus::Approved ? 'disetujui.' : 'ditolak.');

        return $this->note === null || $this->note === '' ? $text : "{$text} Catatan: {$this->note}";
    }

    public function url(): ?string
    {
        return route('admin.approvals.show', ['approval' => $this->approvalId], false);
    }
}
