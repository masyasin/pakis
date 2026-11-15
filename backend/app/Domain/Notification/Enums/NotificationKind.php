<?php

namespace App\Domain\Notification\Enums;

/**
 * Jenis notifikasi (BR-070). Nilai = `notifications.type` & `notification_deliveries.kind`; kanal luar
 * tiap jenis diatur di setting `notification.on_<nilai>`. Jenis absensi ditambah di task-nya.
 */
enum NotificationKind: string
{
    case ExportReady = 'export_ready';
    case ImportDone = 'import_done';
    case PasswordChanged = 'password_changed';
    case NewDeviceLogin = 'new_device_login';
    case AccountLocked = 'account_locked';
    case DevicePending = 'device_pending';

    public function label(): string
    {
        return match ($this) {
            self::ExportReady => 'Ekspor siap',
            self::ImportDone => 'Impor selesai',
            self::PasswordChanged => 'Password diganti',
            self::NewDeviceLogin => 'Login perangkat baru',
            self::AccountLocked => 'Akun dikunci',
            self::DevicePending => 'Perangkat menunggu persetujuan',
        };
    }

    public function settingKey(): string
    {
        return 'notification.on_'.$this->value;
    }

    /** @return array<string, string> nilai => label, untuk filter "Jenis". */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $k) => [$k->value => $k->label()])->all();
    }
}
