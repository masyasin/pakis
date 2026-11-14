<?php

namespace App\Domain\Access\Notifications;

use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * BR-018: login dari browser/perangkat yang belum pernah dipakai dalam 90 hari.
 */
class NewDeviceLogin extends Notification
{
    public function __construct(
        private readonly CarbonInterface $at,
        private readonly string $ip,
        private readonly string $userAgent,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * User-Agent dikirim penyerang dan email dirender sebagai Markdown: buang karakter Markdown/HTML
     * agar tidak bisa menyisipkan tautan atau format palsu ke email keamanan korban.
     */
    public static function plain(string $value): string
    {
        $clean = (string) preg_replace('/[\[\]()<>*_`#|!~\\\\]+/', ' ', $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        return Str::limit($clean, 120);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) setting('general.app_name');

        return (new MailMessage)
            ->subject("Login baru ke {$app}")
            ->greeting('Halo,')
            ->line("Akun Anda baru saja dipakai masuk ke {$app} dari perangkat atau jaringan yang belum pernah dipakai sebelumnya.")
            ->line('Waktu: '.DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i'))
            ->line("Alamat IP: {$this->ip}")
            ->line('Perangkat: '.self::plain($this->userAgent))
            ->line('Bila itu Anda, abaikan email ini. Bila bukan, segera ganti password dan hubungi admin/HRD.');
    }
}
