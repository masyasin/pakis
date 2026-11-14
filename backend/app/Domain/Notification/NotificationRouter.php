<?php

namespace App\Domain\Notification;

use App\Domain\Notification\Channels\ChannelAvailability;
use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Enums\NotificationKind;
use App\Models\User;

/**
 * Kanal luar efektif per notifikasi (BR-070): kanal jenis ∩ kanal global ∩ driver tersedia ∩ alamat
 * penerima. Notifikasi aplikasi tidak lewat sini: selalu dibuat. Diatur admin saja (tanpa
 * preferensi per pengguna, keputusan Mas 15 Nov 2026).
 */
final class NotificationRouter
{
    /**
     * Kanal luar utama untuk jenis ini, berurutan sesuai urutan cadangan (WA → Telegram → Email).
     *
     * @return list<DeliveryChannel>
     */
    public function channelsFor(NotificationKind $kind, User $user): array
    {
        $wanted = (array) setting($kind->settingKey());

        return array_values(array_filter(
            DeliveryChannel::fallbackOrder(),
            fn (DeliveryChannel $c) => in_array($c->value, $wanted, true) && $this->usable($c, $user),
        ));
    }

    /** Kanal boleh dipakai untuk penerima ini (juga sebagai cadangan). */
    public function usable(DeliveryChannel $channel, User $user): bool
    {
        return in_array($channel->value, (array) setting('notification.channels'), true)
            && ChannelAvailability::available($channel)
            && $channel->addressOf($user) !== null;
    }
}
