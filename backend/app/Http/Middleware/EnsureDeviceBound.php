<?php

namespace App\Http\Middleware;

use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Domain\Device\Support\SemVer;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setiap request API ber-token (BR-040, SEC-020, SEC-021):
 * 1. Akun harus masih boleh masuk (aktif, tidak terkunci, pegawai aktif) — selain itu semua token
 *    akun dicabut dan 401 `UNAUTHENTICATED`.
 * 2. Token harus token perangkat (`device:{id}`), perangkatnya milik akun ini, berstatus `active`,
 *    dan `X-Device-Id` sama dengan `device_uid`. Selain itu token dicabut + 401 `DEVICE_MISMATCH`.
 * `last_seen_at` (dan versi app) diperbarui paling sering sekali per 5 menit agar tidak menulis
 * ke DB di setiap request.
 */
class EnsureDeviceBound
{
    private const SEEN_EVERY_SECONDS = 300;

    public function __construct(
        private readonly EmployeeStatus $employees,
        private readonly DeviceTokens $tokens,
        private readonly SecurityLogger $security,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        if (! $user instanceof User || ! $token instanceof PersonalAccessToken) {
            throw new ApiError(ErrorCode::UNAUTHENTICATED);
        }

        if (! $user->is_active || $user->isLocked() || ! $this->employees->isActive($user)) {
            $this->tokens->revokeForUser($user, 'account_inactive');

            throw new ApiError(ErrorCode::UNAUTHENTICATED);
        }

        $deviceId = DeviceTokens::deviceId($token);
        $device = $deviceId === null ? null : Device::query()->find($deviceId);
        $header = (string) $request->header('X-Device-Id', '');

        $bound = $device !== null
            && $device->user_id === $user->id
            && $device->status === DeviceStatus::Active
            && $header !== ''
            && hash_equals($device->device_uid, $header);

        if (! $bound) {
            $token->delete();
            if ($device !== null && $device->token_id === $token->getKey()) {
                $device->forceFill(['token_id' => null])->save();
            }
            $this->security->record(SecurityEventType::TokenRevoked, SecurityChannel::Api, $user, $user->username, [
                'reason' => 'device_mismatch',
                'device_id' => $device?->id,
            ]);

            throw new ApiError(ErrorCode::DEVICE_MISMATCH);
        }

        if ($device->last_seen_at === null || $device->last_seen_at->diffInSeconds(now()) >= self::SEEN_EVERY_SECONDS) {
            $device->forceFill([
                'last_seen_at' => now(),
                'app_version' => SemVer::normalize($request->header('X-App-Version')) ?? $device->app_version,
            ])->saveQuietly();
        }

        $request->attributes->set('device', $device);

        return $next($request);
    }
}
