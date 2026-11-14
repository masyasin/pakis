<?php

namespace App\Domain\Notification\Otp;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Kode OTP lupa password (BR-013): numerik `auth.otp_length` digit, berlaku `auth.otp_minutes`,
 * disimpan sebagai HMAC di cache (bukan teks asli), maksimal 5 kali percobaan lalu hangus.
 *
 * Penghitung percobaan terpisah dan dinaikkan ATOMIK (Cache::increment) SEBELUM kode dibandingkan,
 * sehingga permintaan paralel tidak bisa melampaui batas 5 (tidak ada baca-ubah-tulis yang balapan).
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

        Cache::forget(self::attemptsKey($user));
        Cache::put(self::key($user), ['hash' => self::hash($code), 'expires_at' => $expiresAt->getTimestamp()], $expiresAt);

        $app = (string) setting('general.app_name');
        $this->channels->channel($channel)->send(
            $address,
            "Kode reset password {$app}: {$code}. Berlaku {$minutes} menit. Jangan berikan kode ini kepada siapa pun, termasuk admin.",
        );
    }

    /**
     * Periksa kode TANPA menghabiskannya (kebijakan password baru dicek sesudahnya). Setiap
     * pemeriksaan memakai satu jatah; jatah habis → kode dihapus.
     */
    public function check(User $user, string $code): bool
    {
        $entry = Cache::get(self::key($user));
        if (! is_array($entry) || (int) $entry['expires_at'] <= now()->getTimestamp()) {
            return false;
        }

        $ttl = now()->setTimestamp((int) $entry['expires_at']);
        Cache::add(self::attemptsKey($user), 0, $ttl);
        $attempts = (int) Cache::increment(self::attemptsKey($user));

        if ($attempts > self::MAX_ATTEMPTS) {
            $this->consume($user);

            return false;
        }

        if (hash_equals((string) $entry['hash'], self::hash($code))) {
            return true;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->consume($user);
        }

        return false;
    }

    /** Kode sekali pakai: hapus setelah reset berhasil (atau jatah habis). */
    public function consume(User $user): void
    {
        Cache::forget(self::key($user));
        Cache::forget(self::attemptsKey($user));
    }

    private static function key(User $user): string
    {
        return 'otp:password-reset:'.$user->id;
    }

    private static function attemptsKey(User $user): string
    {
        return 'otp:password-reset:'.$user->id.':attempts';
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
