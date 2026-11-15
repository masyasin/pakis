<?php

namespace App\Domain\Device\Actions;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Notifications\DevicePending;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Daftarkan HP saat login API (BR-040, 06 §7.2):
 * - HP ini sudah `active`/`pending` untuk akun ini → pakai baris itu (perbarui model/OS/versi).
 * - Akun belum punya perangkat aktif, HP tidak aktif di akun lain, dan
 *   `mobile.auto_approve_first_device` → langsung `active`.
 * - Selain itu → `pending` (tanpa token). Notifikasi ke admin hanya saat permintaan baru dibuat,
 *   sehingga login berulang dari HP yang sama tidak membanjiri admin.
 */
final class RegisterDeviceOnLogin
{
    public function __construct(
        private readonly SecurityLogger $security,
        private readonly DataScope $scope,
    ) {}

    /**
     * @param  array{uid: string, model?: string|null, os_version?: string|null}  $info
     */
    public function handle(User $user, array $info, ?string $appVersion): Device
    {
        $details = [
            'model' => $info['model'] ?? null,
            'os_version' => $info['os_version'] ?? null,
            'app_version' => $appVersion,
        ];

        /** @var array{0: Device, 1: bool} $result */
        $result = DB::transaction(function () use ($user, $info, $details) {
            // Kunci baris akun: dua login bersamaan dari HP berbeda tidak sama-sama auto-approve.
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $same = Device::query()->where('user_id', $user->id)->where('device_uid', $info['uid'])
                ->whereIn('status', [DeviceStatus::Active->value, DeviceStatus::Pending->value])
                ->orderByRaw("status = 'active' desc")
                ->first();

            if ($same !== null) {
                $same->fill($details)->save();

                return [$same, false];
            }

            $hasActive = Device::query()->where('user_id', $user->id)->where('status', DeviceStatus::Active->value)->exists();
            $phoneTaken = Device::query()->where('device_uid', $info['uid'])->where('status', DeviceStatus::Active->value)->exists();
            $autoApprove = ! $hasActive && ! $phoneTaken && (bool) setting('mobile.auto_approve_first_device');

            $device = new Device(['user_id' => $user->id, 'device_uid' => $info['uid'], ...$details]);
            $device->forceFill([
                'status' => $autoApprove ? DeviceStatus::Active : DeviceStatus::Pending,
                'approved_at' => $autoApprove ? now() : null,
            ]);

            try {
                // Savepoint: bila index unik menolak, transaksi luar tetap bisa dilanjutkan.
                DB::transaction(fn () => $device->save());
            } catch (UniqueConstraintViolationException) {
                // Balapan dengan login lain: index unik menolak `active` kedua → jadikan permintaan.
                $device->exists = false;
                $device->forceFill(['status' => DeviceStatus::Pending, 'approved_at' => null])->save();
            }

            $this->security->record(SecurityEventType::DeviceRegistered, SecurityChannel::Api, $user, $user->username, [
                'device_id' => $device->id,
                'status' => $device->status->value,
                'model' => $device->model,
            ]);

            return [$device, $device->status === DeviceStatus::Pending];
        });

        [$device, $isNewRequest] = $result;

        if ($isNewRequest) {
            $this->notifyManagers($device);
        }

        return $device;
    }

    /** BR-070: pemegang `devices.manage` yang scope-nya mencakup pemilik perangkat. */
    private function notifyManagers(Device $device): void
    {
        $employeeId = $device->user->employee_id;
        $recipients = User::permission('devices.manage')
            ->where('is_active', true)
            ->whereKeyNot($device->user_id)
            ->get()
            ->filter(fn (User $u) => $this->scope->allows($u, $employeeId));

        Notification::send($recipients, DevicePending::for($device));
    }
}
