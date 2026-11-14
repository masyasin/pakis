<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\AccountLocked;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use App\Support\Http\ClientNetwork;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Catat login gagal dan terapkan lockout (BR-016).
 *
 * Penghitung per (subjek + jaringan asal), berlaku `security.lockout_minutes`:
 * - subjek = AKUN yang ditemukan (`user:<id>`), bukan teks yang diketik. Collation DB tidak
 *   membedakan huruf/aksen, sehingga `sití.aminah` dan `siti.aminah` adalah akun yang sama dan
 *   harus berbagi jatah percobaan. Identifier yang tidak ditemukan memakai teksnya sendiri.
 * - jaringan = IPv4 per alamat, IPv6 per /64 (ClientNetwork).
 *
 * Mencapai `security.login_max_attempts`: akun (bila ada) dikunci sampai `locked_until`, event
 * `lockout`, dan pemilik diberi tahu lewat email. Pesan "terlalu banyak percobaan" sama untuk
 * identifier yang ada maupun tidak, sehingga tidak membocorkan keberadaan akun.
 */
final class RecordLoginFailure
{
    public function __construct(private readonly SecurityLogger $security) {}

    public static function subject(?User $user, string $identifier): string
    {
        return $user !== null ? 'user:'.$user->id : 'id:'.$identifier;
    }

    public static function key(string $subject, ?string $ip): string
    {
        return 'login-failed:'.sha1($subject.'|'.ClientNetwork::key($ip));
    }

    public static function tooManyAttempts(string $subject, ?string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::key($subject, $ip), self::maxAttempts());
    }

    /** Menit tersisa sampai percobaan boleh lagi (dibulatkan ke atas, minimal 1). */
    public static function minutesLeft(string $subject, ?string $ip): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn(self::key($subject, $ip)) / 60));
    }

    public static function clear(string $subject, ?string $ip): void
    {
        RateLimiter::clear(self::key($subject, $ip));
    }

    public function handle(string $identifier, string $ip, ?User $user, string $reason): void
    {
        $this->security->record(SecurityEventType::LoginFailed, SecurityChannel::Web, $user, $identifier, ['reason' => $reason]);

        $attempts = RateLimiter::hit(self::key(self::subject($user, $identifier), $ip), self::lockoutMinutes() * 60);

        if ($attempts !== self::maxAttempts()) {
            return;
        }

        $lockedUntil = now()->addMinutes(self::lockoutMinutes());
        if ($user !== null) {
            $user->forceFill(['locked_until' => $lockedUntil])->saveQuietly();
        }

        $this->security->record(SecurityEventType::Lockout, SecurityChannel::Web, $user, $identifier, [
            'attempts' => $attempts,
            'locked_until' => $lockedUntil->toIso8601String(),
        ]);

        if ($user?->email) {
            $user->notify(new AccountLocked($lockedUntil, $ip));
        }
    }

    private static function maxAttempts(): int
    {
        return (int) setting('security.login_max_attempts');
    }

    private static function lockoutMinutes(): int
    {
        return (int) setting('security.lockout_minutes');
    }
}
