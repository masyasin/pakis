<?php

namespace App\Domain\Access\Listeners;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Auth\Events\Logout;

/** `security_events.logout` untuk setiap keluar sesi web (manual, timeout, akun dinonaktifkan). */
final class RecordLogout
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function handle(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->security->record(SecurityEventType::Logout, SecurityChannel::Web, $event->user, $event->user->username);
        }
    }
}
