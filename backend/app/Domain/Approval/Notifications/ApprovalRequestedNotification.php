<?php

namespace App\Domain\Approval\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Langkah aktif menunggu keputusan penerima (15 §9.1 `approval_requested`) → calon penyetuju langkah
 * aktif, termasuk delegasinya. Teks hanya ringkasan dokumen (tanpa data sensitif).
 */
class ApprovalRequestedNotification extends PakisNotification
{
    public function __construct(
        private readonly int $approvalId,
        private readonly string $documentLabel,
        private readonly string $summary,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ApprovalRequested;
    }

    public function title(): string
    {
        return "Menunggu persetujuan: {$this->documentLabel}";
    }

    public function body(): string
    {
        return "{$this->summary} menunggu keputusan Anda.";
    }

    public function url(): ?string
    {
        return route('admin.approvals.show', ['approval' => $this->approvalId], false);
    }
}
