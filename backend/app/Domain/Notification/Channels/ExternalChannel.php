<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\NotificationRouter;
use App\Domain\Notification\Notifications\PakisNotification;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Kanal Laravel kustom untuk semua kanal luar (BR-070). Satu kanal (bukan `mail` + kanal lain terpisah)
 * agar urutan cadangan bisa diatur: kanal yang dilewati/gagal dialihkan ke kanal SESUDAHNYA dalam
 * urutan WhatsApp → Telegram → Email yang aktif dan dimiliki penerima. Tiap kanal paling banyak
 * dicoba sekali per notifikasi, jadi penerima tidak menerima pesan ganda.
 */
class ExternalChannel
{
    public function __construct(
        private readonly NotificationRouter $router,
        private readonly ChannelGuard $guard,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof PakisNotification) {
            return;
        }

        /** @var list<DeliveryChannel> $attempted */
        $attempted = [];
        foreach ($this->router->channelsFor($notification->kind(), $notifiable) as $channel) {
            $this->attempt($channel, null, $notifiable, $notification, $attempted);
        }
    }

    /**
     * @param  list<DeliveryChannel>  $attempted
     */
    private function attempt(DeliveryChannel $channel, ?DeliveryChannel $from, User $user, PakisNotification $notification, array &$attempted): void
    {
        if (in_array($channel, $attempted, true)) {
            return;
        }
        $attempted[] = $channel;

        if ($this->guard->deliver($channel, $user, $notification, $from) === DeliveryStatus::Sent) {
            return;
        }

        foreach ($channel->fallbacks() as $next) {
            if (! in_array($next, $attempted, true) && $this->router->usable($next, $user)) {
                $this->attempt($next, $channel, $user, $notification, $attempted);

                return;
            }
        }
    }
}
