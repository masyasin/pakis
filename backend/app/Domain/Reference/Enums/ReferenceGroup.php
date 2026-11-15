<?php

namespace App\Domain\Reference\Enums;

/**
 * Grup referensi (08 §3). Daftar ini dikunci di kode: UI tidak bisa membuat grup baru, karena
 * setiap grup terhubung ke kolom FK tertentu.
 */
enum ReferenceGroup: string
{
    case LocationType = 'location_type';
    case EmploymentStatus = 'employment_status';
    case CorrectionReason = 'correction_reason';
    case OffboardingReason = 'offboarding_reason';

    public function label(): string
    {
        return match ($this) {
            self::LocationType => 'Tipe lokasi',
            self::EmploymentStatus => 'Status kerja',
            self::CorrectionReason => 'Alasan koreksi presensi',
            self::OffboardingReason => 'Alasan keluar',
        };
    }

    /**
     * Kolom yang memakai item grup ini (08 §3), untuk menolak hapus item yang sudah dipakai.
     *
     * @return list<array{0: string, 1: string}> [tabel, kolom]
     */
    public function usages(): array
    {
        return match ($this) {
            self::LocationType => [['locations', 'location_type_id']],
            self::EmploymentStatus => [['employees', 'employment_status_id']],
            self::CorrectionReason => [['attendance_corrections', 'reason_id']],
            self::OffboardingReason => [['employees', 'exit_reason_id']],
        };
    }
}
