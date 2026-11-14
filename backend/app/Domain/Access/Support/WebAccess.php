<?php

namespace App\Domain\Access\Support;

use App\Models\User;

/**
 * Gerbang web admin (BR-017, 06 §8): pengguna boleh masuk web bila punya SALAH SATU permission
 * yang membuka menu. Akun dengan role `pegawai` saja hanya untuk aplikasi Pakis Pro.
 *
 * Saat ini dijawab lewat Gate (ability sementara deny-by-default); TODO(T-018): permission Spatie.
 */
final class WebAccess
{
    public const MESSAGE = 'Akun Anda hanya untuk aplikasi Pakis Pro.';

    /** Permission syarat menu web (06 §8), urut sesuai tabel. */
    public const MENU_PERMISSIONS = [
        'dashboard.view',
        'attendance.view',
        'attendance_corrections.view',
        'schedules.view',
        'shifts.view',
        'holidays.view',
        'employees.view',
        'assignments.view',
        'employees.import',
        'locations.view',
        'departments.view',
        'positions.view',
        'regions.view',
        'users.view',
        'roles.view',
        'devices.view',
        'settings.view',
        'sequences.view',
        'references.view',
        'audit.view',
    ];

    public function allows(User $user): bool
    {
        return $user->canAny(self::MENU_PERMISSIONS);
    }
}
