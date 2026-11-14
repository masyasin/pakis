<?php

namespace App\Domain\Access\Notifications;

use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Pemberitahuan password akun baru saja diganti (T-014). Tanpa password atau tautan aksi. */
class PasswordChanged extends Notification
{
    public function __construct(private readonly CarbonInterface $at) {}

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
            ->subject("Password {$app} Anda diganti")
            ->greeting('Halo,')
            ->line("Password akun {$app} Anda baru saja diganti pada ".DisplayTime::toLocal($this->at)->translatedFormat('j M Y H.i').'.')
            ->line('Sesi di perangkat lain sudah dikeluarkan.')
            ->line('Bila bukan Anda yang menggantinya, segera hubungi admin/HRD.');
    }
}
