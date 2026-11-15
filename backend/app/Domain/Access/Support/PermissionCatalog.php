<?php

namespace App\Domain\Access\Support;

/**
 * Daftar permission resmi (06 §3, ADR-011). SATU-SATUNYA sumber permission: PermissionSeeder
 * membuatnya dari sini, UI hanya bisa menetapkan permission yang ada ke role (BR-030).
 * PermissionCatalogTest mem-parse tabel 06 §3 dan membandingkan dua arah.
 */
final class PermissionCatalog
{
    /**
     * Modul → [label modul, [permission → label]]. Urutan = urutan tampil di matriks role.
     *
     * @var array<string, array{0: string, 1: array<string, string>}>
     */
    private const MODULES = [
        'dashboard' => ['Dasbor', ['dashboard.view' => 'Lihat dasbor']],
        'settings' => ['Pengaturan', ['settings.view' => 'Lihat', 'settings.update' => 'Ubah']],
        'sequences' => ['Penomoran', ['sequences.view' => 'Lihat', 'sequences.update' => 'Ubah pola']],
        'references' => ['Referensi', ['references.view' => 'Lihat', 'references.manage' => 'Kelola']],
        'users' => ['Pengguna', [
            'users.view' => 'Lihat',
            'users.create' => 'Tambah',
            'users.update' => 'Ubah',
            'users.deactivate' => 'Nonaktifkan',
            'users.reset_password' => 'Reset password',
            'users.reset_mfa' => 'Reset MFA',
            'users.export' => 'Ekspor',
        ]],
        'roles' => ['Role & hak akses', ['roles.view' => 'Lihat', 'roles.manage' => 'Kelola role & matriks']],
        'audit' => ['Audit', ['audit.view' => 'Lihat audit & log keamanan', 'audit.export' => 'Ekspor']],
        'regions' => ['Wilayah', ['regions.view' => 'Lihat']],
        'locations' => ['Lokasi', ['locations.view' => 'Lihat', 'locations.manage' => 'Kelola']],
        'departments' => ['Departemen', ['departments.view' => 'Lihat', 'departments.manage' => 'Kelola']],
        'positions' => ['Jabatan', ['positions.view' => 'Lihat', 'positions.manage' => 'Kelola']],
        'employees' => ['Pegawai', [
            'employees.view' => 'Lihat',
            'employees.create' => 'Tambah',
            'employees.update' => 'Ubah',
            'employees.delete' => 'Hapus',
            'employees.view_sensitive' => 'Buka NIK lengkap',
            'employees.offboard' => 'Nonaktifkan / aktifkan kembali',
            'employees.import' => 'Impor',
            'employees.export' => 'Ekspor',
        ]],
        'assignments' => ['Penugasan', ['assignments.view' => 'Lihat', 'assignments.manage' => 'Kelola']],
        'devices' => ['Perangkat', ['devices.view' => 'Lihat', 'devices.manage' => 'Setujui / tolak / cabut']],
        'shifts' => ['Shift', ['shifts.view' => 'Lihat', 'shifts.manage' => 'Kelola']],
        'schedules' => ['Jadwal', ['schedules.view' => 'Lihat', 'schedules.manage' => 'Kelola pola & pengecualian']],
        'holidays' => ['Hari libur', ['holidays.view' => 'Lihat', 'holidays.manage' => 'Kelola']],
        'attendance' => ['Presensi', [
            'attendance.view' => 'Lihat',
            'attendance.view_photo' => 'Lihat foto selfie',
            'attendance.export' => 'Ekspor',
            'attendance.self' => 'Presensi & lihat milik sendiri (Pakis Pro)',
        ]],
        'attendance_corrections' => ['Koreksi presensi', [
            'attendance_corrections.view' => 'Lihat',
            'attendance_corrections.create' => 'Ajukan milik sendiri',
            'attendance_corrections.approve' => 'Setujui / tolak',
        ]],
        'field_trips' => ['Tugas luar kota', [
            'field_trips.view' => 'Lihat',
            'field_trips.manage' => 'Buat / ubah / batalkan',
            'field_trips.request' => 'Ajukan milik sendiri (Pakis Pro)',
            'field_trips.approve' => 'Setujui / tolak pengajuan',
        ]],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach (self::MODULES as [, $permissions]) {
            array_push($names, ...array_keys($permissions));
        }

        return $names;
    }

    /**
     * Untuk matriks di form role.
     *
     * @return list<array{module: string, label: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::MODULES as $module => [$label, $permissions]) {
            $groups[] = [
                'module' => $module,
                'label' => $label,
                'permissions' => array_map(
                    fn (string $name, string $permissionLabel) => ['name' => $name, 'label' => $permissionLabel],
                    array_keys($permissions),
                    array_values($permissions),
                ),
            ];
        }

        return $groups;
    }
}
