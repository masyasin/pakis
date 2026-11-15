<?php

namespace App\Domain\Attendance\Enums;

/**
 * Status harian absensi (04 §2.13 `attendance_days.status`; label 07 §6). Nilai identik dengan ENUM DB.
 * Urutan penentuan ada di resolver status (BR-068, T-037), bukan di enum ini.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case NoCheckout = 'no_checkout';
    case Absent = 'absent';
    case Holiday = 'holiday';
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Hadir',
            self::Late => 'Telat',
            self::EarlyLeave => 'Pulang cepat',
            self::NoCheckout => 'Tidak absen pulang',
            self::Absent => 'Tidak hadir',
            self::Holiday => 'Libur',
            self::Off => 'Tidak dijadwalkan',
        };
    }
}
