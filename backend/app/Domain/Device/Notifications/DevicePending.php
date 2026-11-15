<?php

namespace App\Domain\Device\Notifications;

use App\Domain\Device\Models\Device;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * BR-070: perangkat `pending` → pemegang `devices.manage` dalam scope. Tanpa `device_uid` (pengenal
 * teknis); cukup nama pemilik dan model HP agar admin bisa mengenali permintaan.
 */
class DevicePending extends PakisNotification
{
    public function __construct(
        private readonly string $ownerName,
        private readonly string $deviceLabel,
    ) {}

    public static function for(Device $device): self
    {
        return new self($device->user->name, $device->label());
    }

    public function kind(): NotificationKind
    {
        return NotificationKind::DevicePending;
    }

    public function title(): string
    {
        return 'Perangkat baru menunggu persetujuan';
    }

    public function body(): string
    {
        return "{$this->ownerName} masuk Pakis Pro dari HP baru ({$this->deviceLabel}). Setujui atau tolak di menu Perangkat.";
    }

    public function url(): ?string
    {
        return '/perangkat?filter[status][]=pending';
    }
}
