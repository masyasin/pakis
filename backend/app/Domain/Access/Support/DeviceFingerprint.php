<?php

namespace App\Domain\Access\Support;

use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Sidik jari "browser/perangkat" untuk BR-018: User-Agent + jaringan (IPv4 /24, IPv6 /48).
 * Disimpan sebagai hash di context `login_success`, bukan UA/IP mentah, dan tidak dipakai untuk
 * otentikasi — hanya untuk memutuskan apakah login ini dari perangkat yang belum pernah dipakai.
 */
final class DeviceFingerprint
{
    public const LOOKBACK_DAYS = 90;

    public static function of(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent().'|'.self::network((string) $request->ip()));
    }

    /** Pernah login sukses dengan sidik jari ini dalam 90 hari terakhir? */
    public static function isKnown(User $user, string $fingerprint): bool
    {
        return SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('event', SecurityEventType::LoginSuccess)
            ->where('occurred_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->where('context->device', $fingerprint)
            ->exists();
    }

    public static function hasPreviousLogin(User $user): bool
    {
        return SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('event', SecurityEventType::LoginSuccess)
            ->exists();
    }

    private static function network(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0/24';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = (string) inet_pton($ip);

            return bin2hex(substr($packed, 0, 6)).'::/48';
        }

        return $ip;
    }
}
