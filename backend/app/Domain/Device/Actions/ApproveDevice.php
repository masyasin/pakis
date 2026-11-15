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
 * Setujui perangkat `pending` (06 §7.2, BR-040): perangkat aktif lama akun itu → `replaced` dan
 * tokennya dicabut. HP yang masih aktif di akun LAIN ditolak (satu HP satu akun); index unik DB
 * tetap menjadi pengaman terakhir. Pemilik login ulang dari HP itu untuk mendapat token.
 */
final class ApproveDevice
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
        private readonly DeviceTokens $tokens,
    ) {}

    public function handle(Device $device, User $actor): Device
    {
        return DB::transaction(function () use ($device, $actor) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($locked->status !== DeviceStatus::Pending) {
                throw ValidationException::withMessages(['device' => 'Perangkat ini sudah tidak menunggu persetujuan. Muat ulang halaman.']);
            }

            // Mengikat HP untuk akun sendiri harus lewat orang lain (pola SEC-031): HP kedua
            // pemegang `devices.manage` tidak boleh disetujui pemiliknya sendiri.
            if ($locked->user_id === $actor->id) {
                throw ValidationException::withMessages(['device' => 'Perangkat milik akun Anda sendiri harus disetujui admin lain.']);
            }

            $usedElsewhere = Device::query()->where('device_uid', $locked->device_uid)
                ->where('status', DeviceStatus::Active->value)
                ->where('user_id', '!=', $locked->user_id)
                ->first();
            if ($usedElsewhere !== null) {
                // Nama akun lain hanya disebut bila akun itu dalam scope pelaku (06 §5).
                $visible = Device::query()->whereKey($usedElsewhere->id)->visibleTo($actor)->exists();
                $owner = $visible ? "akun {$usedElsewhere->user->name}" : 'akun lain';

                throw ValidationException::withMessages([
                    'device' => "HP ini masih aktif untuk {$owner}. Cabut perangkat itu dulu (satu HP hanya untuk satu akun).",
                ]);
            }

            $previous = Device::query()->where('user_id', $locked->user_id)
                ->where('status', DeviceStatus::Active->value)
                ->lockForUpdate()
                ->get();
            foreach ($previous as $old) {
                $this->tokens->revokeForDevice($old, 'device_replaced');
                // Dicatat di audit `device.approved` (replaced_device_ids), bukan event terpisah (09 §7.1).
                $old->forceFill(['status' => DeviceStatus::Replaced, 'revoked_by' => $actor->id, 'revoked_at' => now()])->save();
            }

            $locked->forceFill(['status' => DeviceStatus::Active, 'approved_by' => $actor->id, 'approved_at' => now()])->save();

            $this->audit->record('device.approved', $locked, ['status' => DeviceStatus::Pending->value], [
                'status' => DeviceStatus::Active->value,
                'replaced_device_ids' => $previous->pluck('id')->all(),
            ]);
            $this->security->record(SecurityEventType::DeviceApproved, SecurityChannel::Web, $locked->user, $locked->user->username, [
                'device_id' => $locked->id,
            ]);

            return $locked;
        });
    }
}
