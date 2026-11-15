<?php

namespace App\Domain\Device\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cabut perangkat `active` (06 §7.2): HP hilang/dijual/pegawai keluar. Token dicabut seketika
 * sehingga request berikutnya dari HP itu ditolak (401). Offboarding (T-026) memakai action ini.
 */
final class RevokeDevice
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
        private readonly DeviceTokens $tokens,
    ) {}

    public function handle(Device $device, ?User $actor, string $reason = 'admin'): Device
    {
        return DB::transaction(function () use ($device, $actor, $reason) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($locked->status !== DeviceStatus::Active) {
                throw ValidationException::withMessages(['device' => 'Hanya perangkat aktif yang bisa dicabut. Muat ulang halaman.']);
            }

            $this->tokens->revokeForDevice($locked, null);
            $locked->forceFill(['status' => DeviceStatus::Revoked, 'revoked_by' => $actor?->id, 'revoked_at' => now()])->save();

            $this->audit->record('device.revoked', $locked, ['status' => DeviceStatus::Active->value], ['status' => DeviceStatus::Revoked->value]);
            $this->security->record(SecurityEventType::DeviceRevoked, SecurityChannel::Web, $locked->user, $locked->user->username, [
                'device_id' => $locked->id,
                'reason' => $reason,
            ]);

            return $locked;
        });
    }
}
