<?php

namespace App\Domain\Audit\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Peringatan keamanan ke `monitoring.alert_emails` (SEC-041, SEC-070, T-045). Isi hanya ringkasan
 * (jenis, jumlah, IP, username, jalur berkas) — tanpa password, token, koordinat, atau NIK.
 */
class SecurityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[Peringatan keamanan] '.$this->title.' — '.setting('general.app_name'));

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->line('Periksa Log Keamanan di aplikasi dan ikuti playbook insiden (09 §6) bila mencurigakan.');
    }
}
