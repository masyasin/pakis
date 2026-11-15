<?php

namespace App\Domain\Access\Support;

use App\Domain\Access\Enums\DataScope;

/**
 * Role bawaan (06 §2) dan matriks permission default (06 §4) untuk RoleSeeder.
 * RoleSeederTest memastikan hasil seeder identik dengan `tests/Fixtures/permission_matrix.php`,
 * dan fixture itu identik dengan tabel dokumen.
 */
final class RoleCatalog
{
    public const SUPER_ADMIN = 'super_admin';

    public const OWNER = 'owner';

    public const PEGAWAI = 'pegawai';

    /** Role yang permission-nya terkunci penuh (BR-031). */
    public const LOCKED = [self::SUPER_ADMIN, self::OWNER];

    /**
     * @return list<array{name: string, label: string, data_scope: DataScope, mfa_required: bool, is_system: bool, description: string}>
     */
    public static function roles(): array
    {
        return [
            ['name' => self::SUPER_ADMIN, 'label' => 'Super Admin', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => true, 'description' => 'Dukungan teknis vendor (Sintech). Bisa dinonaktifkan Owner.'],
            ['name' => self::OWNER, 'label' => 'Owner', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => true, 'description' => 'Pemilik usaha, akses penuh.'],
            ['name' => 'administrator', 'label' => 'Administrator', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => false, 'description' => 'Admin kantor / IT internal. Mengurus sistem, bukan data SDM.'],
            ['name' => 'hrd', 'label' => 'HRD', 'data_scope' => DataScope::All, 'mfa_required' => true, 'is_system' => false, 'description' => 'Staf HRD: data pegawai, jadwal, absensi.'],
            ['name' => 'kepala_lokasi', 'label' => 'Kepala Lokasi', 'data_scope' => DataScope::Location, 'mfa_required' => false, 'is_system' => false, 'description' => 'Kepala gudang / toko, terbatas lokasinya.'],
            ['name' => 'supervisor', 'label' => 'Supervisor', 'data_scope' => DataScope::Team, 'mfa_required' => false, 'is_system' => false, 'description' => 'Supervisor sales, terbatas timnya.'],
            ['name' => self::PEGAWAI, 'label' => 'Pegawai', 'data_scope' => DataScope::Self, 'mfa_required' => false, 'is_system' => true, 'description' => 'Presensi lewat Pakis Pro. Tidak masuk web admin.'],
        ];
    }

    /**
     * Permission default per role (06 §4). `super_admin` = semua.
     *
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        if ($role === self::SUPER_ADMIN || $role === self::OWNER) {
            return PermissionCatalog::names();
        }

        $all = PermissionCatalog::names();
        $except = fn (array $denied) => array_values(array_diff($all, $denied));

        return match ($role) {
            'administrator' => $except([
                'audit.export', 'employees.create', 'employees.update', 'employees.delete', 'employees.view_sensitive',
                'employees.offboard', 'employees.import', 'employees.export', 'assignments.manage', 'shifts.manage',
                'schedules.manage', 'holidays.manage', 'attendance.view_photo', 'attendance.export',
                'attendance_corrections.approve', 'field_trips.manage', 'field_trips.approve',
            ]),
            'hrd' => $except([
                'settings.view', 'settings.update', 'sequences.view', 'sequences.update', 'users.reset_mfa',
                'users.export', 'roles.view', 'roles.manage', 'audit.view', 'audit.export',
            ]),
            'kepala_lokasi' => [
                'dashboard.view', 'locations.view', 'employees.view', 'assignments.view', 'devices.view', 'shifts.view',
                'schedules.view', 'schedules.manage', 'holidays.view', 'attendance.view', 'attendance.view_photo',
                'attendance.export', 'attendance.self', 'attendance_corrections.view', 'attendance_corrections.create',
                'attendance_corrections.approve', 'field_trips.view', 'field_trips.manage', 'field_trips.request',
                'field_trips.approve',
            ],
            'supervisor' => [
                'dashboard.view', 'employees.view', 'assignments.view', 'schedules.view', 'holidays.view',
                'attendance.view', 'attendance.view_photo', 'attendance.self', 'attendance_corrections.view',
                'attendance_corrections.create', 'attendance_corrections.approve', 'field_trips.view',
                'field_trips.manage', 'field_trips.request', 'field_trips.approve',
            ],
            self::PEGAWAI => ['attendance.self', 'attendance_corrections.create', 'field_trips.request'],
            default => [],
        };
    }
}
