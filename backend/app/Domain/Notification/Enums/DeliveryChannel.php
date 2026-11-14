<?php

namespace App\Domain\Notification\Enums;

use App\Models\User;

/**
 * Kanal luar notifikasi (BR-070); nilai identik dengan ENUM `notification_deliveries.channel` dan opsi
 * setting `notification.*`. Kanal aplikasi (database) bukan anggota: selalu aktif.
 */
enum DeliveryChannel: string
{
    case Email = 'email';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';

    /**
     * Urutan cadangan WhatsApp → Telegram → Email: kanal yang dilewati dialihkan ke kanal SESUDAHNYA.
     * Email paling akhir karena paling murah dan tidak berkuota.
     *
     * @return list<self>
     */
    public static function fallbackOrder(): array
    {
        return [self::WhatsApp, self::Telegram, self::Email];
    }

    /** @return list<self> kanal sesudah kanal ini dalam urutan cadangan */
    public function fallbacks(): array
    {
        $order = self::fallbackOrder();

        return array_slice($order, (int) array_search($this, $order, true) + 1);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Telegram => 'Telegram',
            self::WhatsApp => 'WhatsApp',
        };
    }

    /** Alamat tujuan penerima di kanal ini; null = penerima tidak bisa dihubungi lewat kanal ini. */
    public function addressOf(User $user): ?string
    {
        $address = match ($this) {
            self::Email => $user->email,
            self::Telegram => $user->telegram_chat_id,
            self::WhatsApp => $user->phone,
        };

        return filled($address) ? (string) $address : null;
    }
}
