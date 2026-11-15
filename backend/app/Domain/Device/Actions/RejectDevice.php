<?php

namespace App\Domain\Device\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tolak perangkat `pending` dengan alasan wajib (06 §7.2). Login berikutnya dari HP itu membuat permintaan baru. */
final class RejectDevice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Device $device, User $actor, string $reason): Device
    {
        return DB::transaction(function () use ($device, $actor, $reason) {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($locked->status !== DeviceStatus::Pending) {
                throw ValidationException::withMessages(['device' => 'Perangkat ini sudah tidak menunggu persetujuan. Muat ulang halaman.']);
            }

            $locked->forceFill([
                'status' => DeviceStatus::Rejected,
                'request_reason' => $reason,
                'revoked_by' => $actor->id,
                'revoked_at' => now(),
            ])->save();

            $this->audit->record('device.rejected', $locked, ['status' => DeviceStatus::Pending->value], [
                'status' => DeviceStatus::Rejected->value,
                'request_reason' => $reason,
            ]);

            return $locked;
        });
    }
}
