<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\AccountLocked;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use App\Support\Http\ClientNetwork;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Catat login gagal dan terapkan lockout (BR-016). Dua penghitung, keduanya berlaku
 * `security.lockout_minutes`:
 *
 * 1. Per (subjek + jaringan asal), batas `security.login_max_attempts`:
 *    - subjek = AKUN yang ditemukan (`user:<id>`). Collation DB tidak membedakan huruf/aksen,
 *      sehingga `sití.aminah` dan `siti.aminah` adalah akun yang sama dan berbagi jatah.
 *    - identifier yang tidak ditemukan dilipat dengan aturan serupa (huruf kecil, tanpa aksen),
 *      agar varian akun yang tidak ada juga berbagi jatah — perilakunya sama dengan akun yang ada
 *      sehingga tidak bisa dipakai menebak keberadaan akun.
 *    - jaringan = IPv4 per alamat, IPv6 per /64 (ClientNetwork).
 * 2. Per AKUN dari jaringan mana pun, batas `security.account_max_failures` — menahan penyerang
 *    yang berganti-ganti IP.
 *
 * Salah satu batas tercapai: akun dikunci sampai `locked_until`, event `lockout`, email ke pemilik.
 */
final class RecordLoginFailure
{
    public function __construct(private readonly SecurityLogger $security) {}

    public static function subject(?User $user, string $identifier): string
    {
        return $user !== null ? 'user:'.$user->id : 'id:'.Str::lower(Str::ascii($identifier));
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
        return max(1, (int) ceil(self::secondsLeft($subject, $ip) / 60));
    }

    /** Detik tersisa sampai percobaan boleh lagi (minimal 1); `retry_after_s` di API (05 §2.3). */
    public static function secondsLeft(string $subject, ?string $ip): int
    {
        return max(1, RateLimiter::availableIn(self::key($subject, $ip)));
    }

    /** Login berhasil: kosongkan penghitung jaringan ini dan penghitung akun. */
    public static function clear(string $subject, ?string $ip, ?User $user = null): void
    {
        RateLimiter::clear(self::key($subject, $ip));
        if ($user !== null) {
            RateLimiter::clear(self::accountKey($user));
        }
    }

    public function handle(string $identifier, string $ip, ?User $user, string $reason, SecurityChannel $channel = SecurityChannel::Web): void
    {
        $this->security->record(SecurityEventType::LoginFailed, $channel, $user, $identifier, ['reason' => $reason]);

        $decay = self::lockoutMinutes() * 60;
        $attempts = RateLimiter::hit(self::key(self::subject($user, $identifier), $ip), $decay);
        $accountAttempts = $user !== null ? RateLimiter::hit(self::accountKey($user), $decay) : 0;

        // `===` (bukan `>=`): kunci & kirim email sekali saat batas tercapai, bukan di tiap percobaan sesudahnya.
        $reachedNetwork = $attempts === self::maxAttempts();
        $reachedAccount = $user !== null && $accountAttempts === self::accountMaxFailures();
        if (! $reachedNetwork && ! $reachedAccount) {
            return;
        }

        $lockedUntil = now()->addMinutes(self::lockoutMinutes());
        if ($user !== null) {
            $user->forceFill(['locked_until' => $lockedUntil])->saveQuietly();
        }

        $this->security->record(SecurityEventType::Lockout, $channel, $user, $identifier, [
            'scope' => $reachedNetwork ? 'identifier_ip' : 'account',
            'attempts' => $reachedNetwork ? $attempts : $accountAttempts,
            'locked_until' => $lockedUntil->toIso8601String(),
        ]);

        if ($user?->email) {
            $user->notify(new AccountLocked($lockedUntil, $ip));
        }
    }

    private static function accountKey(User $user): string
    {
        return 'login-failed-account:'.$user->id;
    }

    private static function maxAttempts(): int
    {
        return (int) setting('security.login_max_attempts');
    }

    private static function accountMaxFailures(): int
    {
        return (int) setting('security.account_max_failures');
    }

    private static function lockoutMinutes(): int
    {
        return (int) setting('security.lockout_minutes');
    }
}
