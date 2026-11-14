<?php

namespace App\Domain\Access\Notifications;

use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dikirim ke alamat email LAMA saat email akun diganti (FR-010). Alamat baru hanya ditampilkan
 * tersamar, agar email ini tidak membocorkan alamat penuh bila yang mengganti bukan pemiliknya.
 */
class EmailChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?string $newEmail,
        private readonly CarbonInterface $at,
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
        $app = (string) setting('general.app_name');
        $when = DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i');

        $message = (new MailMessage)
            ->subject("Email akun {$app} Anda diganti")
            ->greeting('Halo,');

        $message = $this->newEmail === null
            ? $message->line("Email akun {$app} Anda dihapus pada {$when}.")
            : $message->line("Email akun {$app} Anda diganti menjadi ".self::mask($this->newEmail)." pada {$when}.");

        return $message
            ->line('Email ini tidak lagi menerima tautan reset password.')
            ->line('Bila bukan Anda yang menggantinya, segera hubungi admin/HRD.');
    }

    /** `siti.baru@contoh.test` → `si***@contoh.test`. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
