<?php

namespace App\Domain\Access\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * BR-016: akun dikunci sementara setelah terlalu banyak percobaan login gagal. Hanya memberi tahu
 * pemilik akun; tidak memuat password, token, atau tautan yang bisa dipakai menyerang.
 */
class AccountLocked extends PakisNotification
{
    public function __construct(
        private readonly CarbonInterface $lockedUntil,
        private readonly string $ip,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::AccountLocked;
    }

    public function title(): string
    {
        return 'Akun dikunci sementara';
    }

    public function body(): string
    {
        return "Ada beberapa percobaan masuk dengan password salah (terakhir dari IP {$this->ip}), jadi akun dikunci sampai ".$this->until().'. Bila bukan Anda, hubungi admin/HRD.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) setting('general.app_name');

        return (new MailMessage)
            ->subject("Akun {$app} Anda dikunci sementara")
            ->greeting('Halo,')
            ->line('Ada beberapa percobaan masuk ke akun Anda dengan password yang salah, jadi akun dikunci sementara untuk keamanan.')
            ->line('Akun bisa dipakai lagi mulai '.$this->until().'.')
            ->line("Percobaan terakhir dari alamat IP {$this->ip}.")
            ->line('Bila itu bukan Anda, segera hubungi admin/HRD dan ganti password setelah akun terbuka.');
    }

    private function until(): string
    {
        return DisplayTime::toLocal($this->lockedUntil)->translatedFormat('j M Y H.i');
    }
}
