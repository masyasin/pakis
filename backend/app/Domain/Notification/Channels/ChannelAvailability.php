<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Telegram\TelegramBot;
use RuntimeException;

/**
 * Kanal luar mana yang secara teknis bisa dipakai (driver terpasang). Dipakai validasi Pengaturan
 * (kanal tanpa driver tidak bisa dicentang) dan saat kirim. Pola sama dengan OtpManager.
 */
final class ChannelAvailability
{
    private const WHATSAPP_DRIVERS = ['none', 'log'];

    public static function available(DeliveryChannel $channel): bool
    {
        return match ($channel) {
            DeliveryChannel::Email => true,
            DeliveryChannel::Telegram => app(TelegramBot::class)->configured(),
            DeliveryChannel::WhatsApp => self::whatsAppDriver() === 'log' && ! app()->isProduction(),
        };
    }

    /**
     * Driver `log` menulis isi notifikasi + nomor HP ke berkas log: hanya untuk uji. Dipanggil saat
     * boot agar produksi dengan driver ini gagal start, bukan diam-diam menulis data pribadi ke log.
     */
    public static function assertSafeForEnvironment(): void
    {
        $driver = self::whatsAppDriver();

        if (! in_array($driver, self::WHATSAPP_DRIVERS, true)) {
            throw new RuntimeException("Driver WhatsApp notifikasi [{$driver}] tidak dikenal.");
        }

        if ($driver === 'log' && app()->isProduction()) {
            throw new RuntimeException('Driver WhatsApp notifikasi `log` tidak boleh dipakai di produksi.');
        }
    }

    public static function whatsAppDriver(): string
    {
        return (string) config('notify.whatsapp.driver', 'none');
    }
}
