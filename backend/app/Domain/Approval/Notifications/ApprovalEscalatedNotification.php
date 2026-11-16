<?php

namespace App\Domain\Approval\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Langkah tereskalasi (15 §9.1 `approval_escalated`, BR-153(6), PA-28) → penyetuju langkah +
 * pemegang izin eskalasi. Eskalasi hanya menambah penyetuju, tidak pernah menyetujui otomatis.
 */
class ApprovalEscalatedNotification extends PakisNotification
{
    public function __construct(
        private readonly int $approvalId,
        private readonly string $documentLabel,
        private readonly string $summary,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ApprovalEscalated;
    }

    public function title(): string
    {
        return "Persetujuan tereskalasi: {$this->documentLabel}";
    }

    public function body(): string
    {
        return "{$this->summary} belum diputuskan dan kini juga bisa Anda putuskan.";
    }

    public function url(): ?string
    {
        return route('admin.approvals.show', ['approval' => $this->approvalId], false);
    }
}
