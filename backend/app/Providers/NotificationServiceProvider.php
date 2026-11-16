<?php

namespace App\Providers;

use App\Domain\Notification\Channels\ChannelAvailability;
use App\Domain\Notification\Listeners\SendExportReady;
use App\Domain\Notification\Listeners\SendImportDone;
use App\Support\Export\Events\ExportReady;
use App\Support\Import\Events\ImportProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Notifikasi (T-029, BR-070). Provider terpisah dari AppServiceProvider agar modul ini bisa
 * dikembangkan tanpa menyentuh registrasi modul lain.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ChannelAvailability::assertSafeForEnvironment();

        Event::listen(ExportReady::class, SendExportReady::class);
        Event::listen(ImportProcessed::class, SendImportDone::class);
    }
}
