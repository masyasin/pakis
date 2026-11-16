<?php

namespace App\Domain\Audit\Support;

use App\Domain\Audit\Notifications\SecurityAlert;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Satu pintu peringatan keamanan (SEC-041, SEC-070): selalu ke log (`warning`), dan ke email
 * `monitoring.alert_emails` bila diisi. Daftar email kosong bukan galat — log tetap ada agar
 * pemantau server masih bisa menangkapnya.
 */
final class MonitoringAlert
{
    /**
     * @param  list<string>  $lines
     */
    public function send(string $title, array $lines): void
    {
        Log::warning('security_alert', ['title' => $title, 'lines' => $lines]);

        $emails = array_values(array_filter((array) setting('monitoring.alert_emails'), fn ($e) => is_string($e) && $e !== ''));
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new SecurityAlert($title, $lines));
        }
    }
}
