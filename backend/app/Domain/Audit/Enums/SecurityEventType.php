<?php

namespace App\Domain\Audit\Enums;

/** Jenis event keamanan (SEC-041); nilai identik dengan ENUM `security_events.event` (04 §2.11). */
enum SecurityEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailed = 'login_failed';
    case Lockout = 'lockout';
    case Logout = 'logout';
    case NewDeviceLogin = 'new_device_login';
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordResetByAdmin = 'password_reset_by_admin';
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';
    case MfaFailed = 'mfa_failed';
    case MfaResetByAdmin = 'mfa_reset_by_admin';
    case SessionRevoked = 'session_revoked';
    case TokenRevoked = 'token_revoked';
    case RoleChanged = 'role_changed';
    case PermissionChanged = 'permission_changed';
    case DeviceRegistered = 'device_registered';
    case DeviceApproved = 'device_approved';
    case DeviceRevoked = 'device_revoked';
    case AttendanceMockDetected = 'attendance_mock_detected';
    case SensitiveDataViewed = 'sensitive_data_viewed';
    case MassExport = 'mass_export';
    case TelegramLinked = 'telegram_linked';

    public function label(): string
    {
        return match ($this) {
            self::LoginSuccess => 'Login berhasil',
            self::LoginFailed => 'Login gagal',
            self::Lockout => 'Akun terkunci',
            self::Logout => 'Keluar',
            self::NewDeviceLogin => 'Login dari perangkat baru',
            self::PasswordChanged => 'Password diganti',
            self::PasswordResetRequested => 'Minta reset password',
            self::PasswordResetCompleted => 'Reset password selesai',
            self::PasswordResetByAdmin => 'Password direset admin',
            self::MfaEnabled => 'MFA diaktifkan',
            self::MfaDisabled => 'MFA dimatikan',
            self::MfaFailed => 'Kode MFA salah',
            self::MfaResetByAdmin => 'MFA direset admin',
            self::SessionRevoked => 'Sesi dicabut',
            self::TokenRevoked => 'Token dicabut',
            self::RoleChanged => 'Role diubah',
            self::PermissionChanged => 'Permission diubah',
            self::DeviceRegistered => 'Perangkat didaftarkan',
            self::DeviceApproved => 'Perangkat disetujui',
            self::DeviceRevoked => 'Perangkat dicabut',
            self::AttendanceMockDetected => 'Lokasi palsu terdeteksi',
            self::SensitiveDataViewed => 'Data sensitif dilihat',
            self::MassExport => 'Ekspor massal',
            self::TelegramLinked => 'Telegram ditautkan',
        };
    }
}
