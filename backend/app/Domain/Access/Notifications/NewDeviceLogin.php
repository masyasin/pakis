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

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) setting('general.app_name');

        return (new MailMessage)
            ->subject("Login baru ke {$app}")
            ->greeting('Halo,')
            ->line("Akun Anda baru saja dipakai masuk ke {$app} dari perangkat atau jaringan yang belum pernah dipakai sebelumnya.")
            ->line('Waktu: '.DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i'))
            ->line("Alamat IP: {$this->ip}")
            ->line('Perangkat: '.Str::limit($this->userAgent, 120))
            ->line('Bila itu Anda, abaikan email ini. Bila bukan, segera ganti password dan hubungi admin/HRD.');
    }
}
