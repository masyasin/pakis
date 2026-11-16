<?php

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Notifications\ImportDoneNotification;
use App\Support\Import\Events\ImportProcessed;

/** ImportProcessed → notifikasi ke pengunggah (FR-028, BR-070). */
class SendImportDone
{
    public function handle(ImportProcessed $event): void
    {
        $event->batch->creator?->notify(new ImportDoneNotification($event->batch));
    }
}
