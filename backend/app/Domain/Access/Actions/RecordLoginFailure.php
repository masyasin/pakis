<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\AccountLocked;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Catat login gagal dan terapkan lockout (BR-016).
 *
 * Penghitung per (identifier + IP), berlaku `security.lockout_minutes`. Saat mencapai
 * `security.login_max_attempts`: akun (bila ada) dikunci sampai `locked_until`, event `lockout`,
 * dan pemilik diberi tahu lewat email. Identifier yang tidak ada diperlakukan sama di sisi
 * penghitung, sehingga pesan "terlalu banyak percobaan" tidak membocorkan keberadaan akun.
 */
final class RecordLoginFailure
{
    public function __construct(private readonly SecurityLogger $security) {}

    public static function key(string $identifier, string $ip): string
    {
        return 'login-failed:'.sha1($identifier.'|'.$ip);
    }

    public static function tooManyAttempts(string $identifier, string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::key($identifier, $ip), self::maxAttempts());
    }

    /** Menit tersisa sampai percobaan boleh lagi (dibulatkan ke atas, minimal 1). */
    public static function minutesLeft(string $identifier, string $ip): int
    {
        return max(1, (int) ceil(RateLimiter::availableIn(self::key($identifier, $ip)) / 60));
    }

    public static function clear(string $identifier, string $ip): void
    {
        RateLimiter::clear(self::key($identifier, $ip));
    }

    public function handle(string $identifier, string $ip, ?User $user, string $reason): void
    {
        $this->security->record(SecurityEventType::LoginFailed, SecurityChannel::Web, $user, $identifier, ['reason' => $reason]);

        $key = self::key($identifier, $ip);
        $attempts = RateLimiter::hit($key, self::lockoutMinutes() * 60);

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
