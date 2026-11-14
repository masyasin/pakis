<?php

namespace App\Domain\Notification\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Support\Export\ExportFile;

/** Ekspor antrean selesai (BR-005, BR-070) → pembuat ekspor. */
class ExportReadyNotification extends PakisNotification
{
    public function __construct(private readonly ExportFile $file) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::ExportReady;
    }

    public function title(): string
    {
        return 'Berkas ekspor siap';
    }

    public function body(): string
    {
        return sprintf('Berkas ekspor Anda (%s baris) siap diunduh di menu Unduhan.', number_format((int) $this->file->row_count, 0, ',', '.'));
    }

    public function url(): ?string
    {
        return '/unduhan';
    }
}
