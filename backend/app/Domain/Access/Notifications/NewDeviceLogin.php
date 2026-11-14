<?php

namespace App\Domain\Access\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * BR-018: login dari browser/perangkat yang belum pernah dipakai dalam 90 hari.
 */
class NewDeviceLogin extends PakisNotification
{
    public function __construct(
        private readonly CarbonInterface $at,
        private readonly string $ip,
        private readonly string $userAgent,
    ) {}

    /**
     * User-Agent dikirim penyerang dan email dirender sebagai Markdown: buang karakter Markdown/HTML
     * agar tidak bisa menyisipkan tautan atau format palsu ke email keamanan korban. Teks yang sama
     * dipakai untuk notifikasi aplikasi, Telegram, dan WhatsApp.
     */
    public static function plain(string $value): string
    {
        $clean = (string) preg_replace('/[\[\]()<>*_`#|!~\\\\]+/', ' ', $value);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));

        return Str::limit($clean, 120);
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::NewDeviceLogin;
    }

    public function title(): string
    {
        return 'Login dari perangkat baru';
    }

    public function body(): string
    {
        return 'Akun Anda dipakai masuk pada '.$this->when()." dari perangkat atau jaringan baru (IP {$this->ip}, ".self::plain($this->userAgent).'). Bila bukan Anda, segera ganti password dan hubungi admin/HRD.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) setting('general.app_name');

        return (new MailMessage)
            ->subject("Login baru ke {$app}")
            ->greeting('Halo,')
            ->line("Akun Anda baru saja dipakai masuk ke {$app} dari perangkat atau jaringan yang belum pernah dipakai sebelumnya.")
            ->line('Waktu: '.$this->when())
            ->line("Alamat IP: {$this->ip}")
            ->line('Perangkat: '.self::plain($this->userAgent))
            ->line('Bila itu Anda, abaikan email ini. Bila bukan, segera ganti password dan hubungi admin/HRD.');
    }

    private function when(): string
    {
        return DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i');
    }
}
