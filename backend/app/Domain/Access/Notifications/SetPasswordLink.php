<?php

namespace App\Domain\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tautan atur password untuk akun baru yang dibuat admin (07 §4 Pengguna, keputusan Mas
 * 15 Nov 2026). Memakai token & halaman reset password yang sama (BR-012, SEC-004): sekali pakai,
 * berumur `security.reset_token_minutes`. URL dibangun dari APP_URL, bukan host permintaan.
 */
class SetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) setting('general.app_name');
        $minutes = (int) setting('security.reset_token_minutes');
        $email = (string) ($notifiable->email ?? '');
        $username = (string) ($notifiable->username ?? '');

        $url = rtrim((string) config('app.url'), '/').'/reset-password/'.$this->token.'?'.http_build_query(['email' => $email]);

        return (new MailMessage)
            ->subject("Akun {$app} Anda sudah dibuat")
            ->greeting('Halo,')
            ->line("Admin membuatkan akun {$app} untuk Anda dengan username **{$username}**.")
            ->action('Atur password', $url)
            ->line("Tautan ini berlaku {$minutes} menit dan hanya bisa dipakai sekali. Bila kedaluwarsa, minta admin mereset password Anda.")
            ->line('Bila Anda tidak merasa memiliki akun ini, abaikan email ini.');
    }
}
