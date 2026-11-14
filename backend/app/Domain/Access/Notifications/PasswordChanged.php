<?php

namespace App\Domain\Access\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;

/** Pemberitahuan password akun baru saja diganti (T-014). Tanpa password atau tautan aksi. */
class PasswordChanged extends PakisNotification
{
    public function __construct(private readonly CarbonInterface $at) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::PasswordChanged;
    }

    public function title(): string
    {
        return 'Password akun diganti';
    }

    public function body(): string
    {
        return 'Password akun Anda diganti pada '.$this->when().'. Sesi di perangkat lain sudah dikeluarkan. Bila bukan Anda, segera hubungi admin/HRD.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) setting('general.app_name');

        return (new MailMessage)
            ->subject("Password {$app} Anda diganti")
            ->greeting('Halo,')
            ->line("Password akun {$app} Anda baru saja diganti pada ".$this->when().'.')
            ->line('Sesi di perangkat lain sudah dikeluarkan.')
            ->line('Bila bukan Anda yang menggantinya, segera hubungi admin/HRD.');
    }

    private function when(): string
    {
        return DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i');
    }
}
