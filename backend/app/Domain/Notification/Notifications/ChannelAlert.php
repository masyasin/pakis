<?php

namespace App\Domain\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Peringatan operasional ke `monitoring.alert_emails`: kuota WhatsApp habis / kanal dijeda (BR-070). */
class ChannelAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Peringatan kanal notifikasi '.setting('general.app_name'))
            ->line($this->message)
            ->line('Periksa kuota/penyedia, lalu atur kanal di Pengaturan → Notifikasi bila perlu.');
    }
}
