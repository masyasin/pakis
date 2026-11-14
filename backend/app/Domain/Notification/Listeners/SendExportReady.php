<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Notifications\ExportReadyNotification;
use App\Support\Export\Events\ExportReady;

/** ExportReady → notifikasi ke pembuat ekspor (FR-029, BR-070). */
class SendExportReady
{
    public function handle(ExportReady $event): void
    {
        $event->file->creator?->notify(new ExportReadyNotification($event->file));
    }
}
