<?php

namespace App\Domain\Access\Notifications;

use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * BR-016: akun dikunci sementara setelah terlalu banyak percobaan login gagal. Hanya memberi tahu
 * pemilik akun; tidak memuat password, token, atau tautan yang bisa dipakai menyerang.
 */
class AccountLocked extends Notification
{
    public function __construct(
        private readonly CarbonInterface $lockedUntil,
        private readonly string $ip,
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
            ->subject("Akun {$app} Anda dikunci sementara")
            ->greeting('Halo,')
            ->line('Ada beberapa percobaan masuk ke akun Anda dengan password yang salah, jadi akun dikunci sementara untuk keamanan.')
            ->line('Akun bisa dipakai lagi mulai '.DisplayTime::toLocal($this->lockedUntil)->translatedFormat('j M Y H.i').'.')
            ->line("Percobaan terakhir dari alamat IP {$this->ip}.")
            ->line('Bila itu bukan Anda, segera hubungi admin/HRD dan ganti password setelah akun terbuka.');
    }
}
