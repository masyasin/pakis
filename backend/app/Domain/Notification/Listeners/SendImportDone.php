<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Employee\Events\ImportProcessed;
use App\Domain\Notification\Notifications\ImportDoneNotification;

/** ImportProcessed → notifikasi ke pengunggah (FR-028, BR-070). */
class SendImportDone
{
    public function handle(ImportProcessed $event): void
    {
        $event->batch->creator?->notify(new ImportDoneNotification($event->batch));
    }
}
