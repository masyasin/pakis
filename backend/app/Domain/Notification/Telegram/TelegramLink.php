<?php

namespace App\Domain\Notification\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Penautan akun ↔ chat Telegram (keputusan Mas 15 Nov 2026). Profil (T-017) menampilkan tautan
 * `t.me/<bot>?start=<kode>`; bot menerima `/start <kode>` dan HandleTelegramUpdate menyimpan
 * chat_id. Kode acak 40 karakter, sekali pakai, berlaku 10 menit.
 */
final class TelegramLink
{
    public const MINUTES = 10;

    /** Tombol "Hubungkan Telegram" hanya berguna bila bot sungguhan aktif (driver `bot` + token). */
    public static function available(): bool
    {
        return config('otp.channels.telegram.driver') === 'bot' && app(TelegramBot::class)->configured();
    }

    public function create(User $user): string
    {
        $code = Str::random(40);
        Cache::put(self::key($code), $user->id, now()->addMinutes(self::MINUTES));

        return 'https://t.me/'.config('otp.telegram.bot_username').'?start='.$code;
    }

    /** Ambil & hapus pemilik kode (sekali pakai). */
    public function consume(string $code): ?int
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $code) !== 1) {
            return null;
        }

        $userId = Cache::pull(self::key($code));

        return is_int($userId) ? $userId : null;
    }

    private static function key(string $code): string
    {
        return 'telegram-link:'.hash('sha256', $code);
    }
}
