<?php

namespace App\Domain\Device\Support;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Models\Device;
use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token Sanctum terikat perangkat (BR-040, SEC-020): nama `device:{id}`, ability = permission
 * mobile, kedaluwarsa `mobile.token_days`. Satu perangkat satu token aktif — token lama perangkat
 * yang sama dicabut saat token baru dibuat (login ulang).
 */
final class DeviceTokens
{
    public const PREFIX = 'device:';

    public function __construct(private readonly SecurityLogger $security) {}

    public function issue(User $user, Device $device): NewAccessToken
    {
        $this->revokeForDevice($device, null);

        $token = $user->createToken(
            self::PREFIX.$device->id,
            MobileAccess::abilities($user),
            now()->addDays((int) setting('mobile.token_days')),
        );

        $device->forceFill(['token_id' => $token->accessToken->getKey()])->save();

        return $token;
    }

    /** ID perangkat dari nama token; null bila bukan token perangkat. */
    public static function deviceId(PersonalAccessToken $token): ?int
    {
        $name = (string) $token->name;

        return str_starts_with($name, self::PREFIX) && ctype_digit(substr($name, strlen(self::PREFIX)))
            ? (int) substr($name, strlen(self::PREFIX))
            : null;
    }

    /** Cabut semua token perangkat ini. `$reason` diisi bila perlu dicatat sebagai event keamanan. */
    public function revokeForDevice(Device $device, ?string $reason): int
    {
        $count = PersonalAccessToken::query()->where('name', self::PREFIX.$device->id)->delete();
        if ($device->token_id !== null) {
            $device->forceFill(['token_id' => null])->save();
        }

        if ($count > 0 && $reason !== null) {
            $this->security->record(SecurityEventType::TokenRevoked, SecurityChannel::Api, $device->user, $device->user->username, [
                'device_id' => $device->id, 'reason' => $reason,
            ]);
        }

        return $count;
    }

    /**
     * Cabut semua token API akun (ganti/reset password, reset MFA, cabut sesi oleh admin, akun
     * dinonaktifkan). `$exceptTokenId` = token yang sedang dipakai (ganti password dari HP itu sendiri).
     */
    public function revokeForUser(User $user, string $reason, ?int $exceptTokenId = null): int
    {
        $query = $user->tokens();
        if ($exceptTokenId !== null) {
            $query->whereKeyNot($exceptTokenId);
        }

        $count = $query->delete();
        Device::query()->where('user_id', $user->id)
            ->when($exceptTokenId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('token_id')->orWhere('token_id', '!=', $exceptTokenId)))
            ->whereNotNull('token_id')
            ->update(['token_id' => null]);

        if ($count > 0) {
            $this->security->record(SecurityEventType::TokenRevoked, SecurityChannel::System, $user, $user->username, [
                'reason' => $reason, 'count' => $count,
            ]);
        }

        return $count;
    }
}
