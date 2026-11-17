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
    case AttendanceMock = 'attendance_mock';
    case CorrectionSubmitted = 'correction_submitted';
    case CorrectionDecided = 'correction_decided';
    case FieldTripSubmitted = 'field_trip_submitted';
    case FieldTripDecided = 'field_trip_decided';
    // Paket A (15 §9.1, T-067).
    case ApprovalRequested = 'approval_requested';
    case ApprovalDecided = 'approval_decided';
    case ApprovalEscalated = 'approval_escalated';
    // Paket A (15 §9.1)
    case RegistrationExpiring = 'registration_expiring';
    case CustomerPhoneChanged = 'customer_phone_changed';
    case StockReconcileMismatch = 'stock_reconcile_mismatch';
    case BatchNearExpiry = 'batch_near_expiry';
    case BatchExpired = 'batch_expired';

    public function label(): string
    {
        return match ($this) {
            self::ExportReady => 'Ekspor siap',
            self::ImportDone => 'Impor selesai',
            self::PasswordChanged => 'Password diganti',
            self::NewDeviceLogin => 'Login perangkat baru',
            self::AccountLocked => 'Akun dikunci',
            self::DevicePending => 'Perangkat menunggu persetujuan',
            self::AttendanceMock => 'Lokasi palsu terdeteksi',
            self::CorrectionSubmitted => 'Koreksi presensi diajukan',
            self::CorrectionDecided => 'Koreksi presensi diputuskan',
            self::FieldTripSubmitted => 'Tugas luar kota diajukan',
            self::FieldTripDecided => 'Tugas luar kota diputuskan',
            self::ApprovalRequested => 'Menunggu persetujuan',
            self::ApprovalDecided => 'Persetujuan diputuskan',
            self::ApprovalEscalated => 'Persetujuan tereskalasi',
            self::RegistrationExpiring => 'Izin edar akan habis',
            self::CustomerPhoneChanged => 'HP kios diganti',
            self::StockReconcileMismatch => 'Selisih rekonsiliasi stok',
            self::BatchNearExpiry => 'Batch mendekati ED',
            self::BatchExpired => 'Batch kedaluwarsa',
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
