<?php

namespace App\Domain\Employee\Enums;

/**
 * Status pegawai (06 §7.3); nilai identik dengan ENUM `employees.status`. Perubahan hanya lewat
 * Nonaktifkan / Aktifkan kembali (T-026), tidak pernah dari form ubah (SEC-034).
 */
enum EmployeeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Inactive => 'Nonaktif',
        };
    }
}
