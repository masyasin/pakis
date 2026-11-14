<?php

namespace App\Support\Menu;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Menu web dari matriks 06 §8. Server yang memutuskan item mana yang tampil (06 §10): permission
 * pengguna DAN route modulnya sudah ada — modul yang belum dibangun otomatis tersembunyi, jadi
 * menu tumbuh sendiri seiring task berjalan. Menyembunyikan menu hanya UX; route tetap dijaga
 * middleware `can:` masing-masing.
 */
final class MenuBuilder
{
    /**
     * [grup, judul, kunci ikon (dipetakan di frontend), permission syarat, nama route].
     * Grup null = tanpa judul grup (Dasbor).
     *
     * @var list<array{0: string|null, 1: string, 2: string, 3: string, 4: string}>
     */
    public const ITEMS = [
        [null, 'Dasbor', 'dashboard', 'dashboard.view', 'admin.dashboard'],
        ['Absensi', 'Absensi Harian', 'attendance', 'attendance.view', 'admin.attendance.index'],
        ['Absensi', 'Rekap Bulanan', 'recap', 'attendance.view', 'admin.attendance.recap'],
        ['Absensi', 'Koreksi Absen', 'correction', 'attendance_corrections.view', 'admin.corrections.index'],
        ['Absensi', 'Jadwal', 'schedule', 'schedules.view', 'admin.schedules.index'],
        ['Absensi', 'Shift', 'shift', 'shifts.view', 'admin.shifts.index'],
        ['Absensi', 'Hari Libur', 'holiday', 'holidays.view', 'admin.holidays.index'],
        ['Kepegawaian', 'Pegawai', 'employee', 'employees.view', 'admin.employees.index'],
        ['Kepegawaian', 'Penugasan', 'assignment', 'assignments.view', 'admin.assignments.index'],
        ['Kepegawaian', 'Impor Pegawai', 'import', 'employees.import', 'admin.imports.index'],
        ['Organisasi', 'Lokasi', 'location', 'locations.view', 'admin.locations.index'],
        ['Organisasi', 'Departemen', 'department', 'departments.view', 'admin.departments.index'],
        ['Organisasi', 'Jabatan', 'position', 'positions.view', 'admin.positions.index'],
        ['Organisasi', 'Wilayah', 'region', 'regions.view', 'admin.regions.index'],
        ['Akses', 'Pengguna', 'user', 'users.view', 'admin.users.index'],
        ['Akses', 'Role & Hak Akses', 'role', 'roles.view', 'admin.roles.index'],
        ['Akses', 'Perangkat', 'device', 'devices.view', 'admin.devices.index'],
        ['Sistem', 'Pengaturan', 'settings', 'settings.view', 'admin.settings.edit'],
        ['Sistem', 'Penomoran', 'sequence', 'sequences.view', 'admin.sequences.index'],
        ['Sistem', 'Referensi', 'reference', 'references.view', 'admin.references.index'],
        ['Sistem', 'Audit Log', 'audit', 'audit.view', 'admin.audit.index'],
        ['Sistem', 'Log Keamanan', 'security', 'audit.view', 'admin.security-events.index'],
    ];

    /** @return list<string> permission yang membuka setidaknya satu menu (BR-017). */
    public static function permissions(): array
    {
        return array_values(array_unique(array_column(self::ITEMS, 3)));
    }

    /**
     * @return list<array{title: string|null, items: list<array{title: string, href: string, icon: string}>}>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (self::ITEMS as [$group, $title, $icon, $permission, $route]) {
            if (! Route::has($route) || ! $user->can($permission)) {
                continue;
            }

            $key = $group ?? '';
            $groups[$key] ??= ['title' => $group, 'items' => []];
            // Relatif: aman dari perbedaan host APP_URL dan cocok untuk penanda item aktif.
            $groups[$key]['items'][] = ['title' => $title, 'href' => route($route, absolute: false), 'icon' => $icon];
        }

        return array_values($groups);
    }
}
