<?php

namespace App\Domain\Access\Notifications;

use App\Domain\Access\Support\UserAgentLabel;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

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
     * User-Agent dikirim oleh si penyerang sendiri (yang sudah memegang password korban). Teks
     * mentahnya — bahkan tanpa Markdown — tetap bisa memuat domain/URL yang dijadikan tautan otomatis
     * oleh Telegram, WhatsApp, dan klien email, sehingga peringatan keamanan resmi berubah menjadi
     * umpan phishing. Karena itu hanya label hasil pencocokan daftar tetap yang ditampilkan.
     */
    public function device(): string
    {
        ['browser' => $browser, 'platform' => $platform] = UserAgentLabel::parse($this->userAgent);

        return "{$browser} · {$platform}";
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
        return 'Akun Anda dipakai masuk pada '.$this->when()." dari perangkat atau jaringan baru ({$this->device()}, IP {$this->ip}). Bila bukan Anda, segera ganti password dan hubungi admin/HRD.";
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
            ->line('Perangkat: '.$this->device())
            ->line('Bila itu Anda, abaikan email ini. Bila bukan, segera ganti password dan hubungi admin/HRD.');
    }

    private function when(): string
    {
        return DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i');
    }
}
