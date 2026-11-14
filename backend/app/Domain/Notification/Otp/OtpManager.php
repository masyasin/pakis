<?php

namespace App\Domain\Notification\Otp;

use App\Domain\Notification\Telegram\TelegramBot;
use RuntimeException;

/**
 * Memilih driver OTP per kanal dari `config/otp.php` (BR-013, OQ-004).
 *
 * Driver `log` menulis kode OTP ke berkas log — aman untuk uji, BERBAHAYA di produksi (siapa pun
 * yang bisa membaca log bisa mereset password orang). assertSafeForEnvironment() dipanggil saat
 * boot sehingga produksi dengan driver `log` gagal start, bukan diam-diam membocorkan kode.
 */
final class OtpManager
{
    public const CHANNELS = ['whatsapp', 'telegram'];

    private const DRIVERS = ['whatsapp' => ['none', 'log'], 'telegram' => ['none', 'log', 'bot']];

    private ?OtpChannel $fake = null;

    public static function assertSafeForEnvironment(): void
    {
        foreach (self::CHANNELS as $channel) {
            $driver = self::driver($channel);

            if (! in_array($driver, self::DRIVERS[$channel], true)) {
                throw new RuntimeException("Driver OTP [{$driver}] untuk kanal {$channel} tidak dikenal.");
            }

            if ($driver === 'log' && app()->isProduction()) {
                throw new RuntimeException("Driver OTP `log` (kanal {$channel}) tidak boleh dipakai di produksi: kode OTP akan tertulis di log.");
            }
        }
    }

    /** Kanal bisa dipakai: driver bukan `none`, bukan `log` di produksi, dan (bot) sudah dikonfigurasi. */
    public static function available(string $channel): bool
    {
        return match (self::driver($channel)) {
            'log' => ! app()->isProduction(),
            'bot' => app(TelegramBot::class)->configured(),
            default => false,
        };
    }

    public function channel(string $channel): OtpChannel
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return match (self::driver($channel)) {
            'log' => new LogOtpChannel($channel),
            'bot' => new TelegramBotOtpChannel(app(TelegramBot::class)),
            default => new NullOtpChannel,
        };
    }

    /** Untuk test: semua kanal mengirim lewat implementasi ini. */
    public function fake(OtpChannel $channel): void
    {
        $this->fake = $channel;
    }

    private static function driver(string $channel): string
    {
        return (string) config("otp.channels.{$channel}.driver", 'none');
    }
}
