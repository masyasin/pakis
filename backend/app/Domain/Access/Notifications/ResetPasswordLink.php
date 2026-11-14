<?php

namespace App\Domain\Access\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tautan reset password (BR-012, SEC-004).
 *
 * - URL dibangun dari APP_URL, BUKAN host permintaan: Host header palsu tidak bisa membelokkan
 *   tautan ke situs penipu.
 * - Diantrikan: waktu respons permintaan tidak membedakan akun yang ada (kirim email) dan tidak ada.
 */
class ResetPasswordLink extends Notification implements ShouldQueue
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

        $url = rtrim((string) config('app.url'), '/').'/reset-password/'.$this->token.'?'.http_build_query(['email' => $email]);

        return (new MailMessage)
            ->subject("Reset password {$app}")
            ->greeting('Halo,')
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->action('Buat password baru', $url)
            ->line("Tautan ini berlaku {$minutes} menit dan hanya bisa dipakai sekali.")
            ->line('Bila Anda tidak meminta reset, abaikan email ini — password Anda tidak berubah.');
    }
}
