<?php

namespace App\Domain\Notification\Otp;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Kode OTP lupa password (BR-013): numerik `auth.otp_length` digit, berlaku `auth.otp_minutes`,
 * disimpan sebagai HMAC di cache (bukan teks asli), maksimal 5 kali salah lalu hangus.
 */
final class OtpService
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly OtpManager $channels) {}

    public function issue(User $user, string $channel, string $address): void
    {
        $length = (int) setting('auth.otp_length');
        $minutes = (int) setting('auth.otp_minutes');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        $expiresAt = now()->addMinutes($minutes);
        Cache::put(self::key($user), [
            'hash' => self::hash($code),
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        $app = (string) setting('general.app_name');
        $this->channels->channel($channel)->send(
            $address,
            "Kode reset password {$app}: {$code}. Berlaku {$minutes} menit. Jangan berikan kode ini kepada siapa pun, termasuk admin.",
        );
    }

    /**
     * Periksa kode TANPA menghabiskannya (kebijakan password baru dicek sesudahnya). Salah →
     * percobaan bertambah; mencapai batas → kode dihapus.
     */
    public function check(User $user, string $code): bool
    {
        $entry = Cache::get(self::key($user));
        if (! is_array($entry) || self::secondsLeft($entry) <= 0) {
            return false;
        }

        if (hash_equals((string) $entry['hash'], self::hash($code))) {
            return true;
        }

        $attempts = (int) $entry['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget(self::key($user));
        } else {
            // Masa berlaku tidak diperpanjang: simpan ulang dengan sisa waktu kode semula.
            Cache::put(self::key($user), [...$entry, 'attempts' => $attempts], now()->addSeconds(max(1, self::secondsLeft($entry))));
        }

        return false;
    }

    /** Kode sekali pakai: hapus setelah reset berhasil. */
    public function consume(User $user): void
    {
        Cache::forget(self::key($user));
    }

    private static function key(User $user): string
    {
        return 'otp:password-reset:'.$user->id;
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function secondsLeft(array $entry): int
    {
        return (int) ($entry['expires_at'] ?? 0) - now()->getTimestamp();
    }
}
