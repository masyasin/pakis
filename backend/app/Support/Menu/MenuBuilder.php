<?php

namespace App\Support\Menu;

use App\Domain\Approval\Support\ApprovalVisibility;
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
     * Seksi sidebar (judul kecil tanpa klik) per grup; keputusan Mas 16 Nov 2026 (06 §8). HRIS =
     * Unit Kerja, Kepegawaian, Presensi (lalu Cuti, Payroll, dst. di Fase HR); Sistem = akses,
     * pengaturan, audit. Modul bisnis nanti cukup menambah seksi baru tanpa merombak yang ada.
     *
     * @var array<string, string>
     */
    public const SECTIONS = [
        'Unit Kerja' => 'HRIS',
        'Kepegawaian' => 'HRIS',
        'Presensi' => 'HRIS',
        'Pengguna & Akses' => 'Sistem',
        'Pengaturan' => 'Sistem',
        'Audit' => 'Sistem',
    ];

    /**
     * [grup, judul, kunci ikon (dipetakan di frontend), permission syarat, nama route].
     * Grup null = tanpa judul grup (Dasbor). Urutan di sini = urutan di sidebar.
     *
     * @var list<array{0: string|null, 1: string, 2: string, 3: string, 4: string}>
     */
    public const ITEMS = [
        [null, 'Dasbor', 'dashboard', 'dashboard.view', 'admin.dashboard'],
        // Persetujuan lintas modul (15 §6.5, PA-27): menu untuk pemegang `approvals.view`. Calon
        // penyetuju tanpa izin itu tetap bisa membuka /persetujuan (tautan notifikasi).
        [null, 'Persetujuan', 'approval', 'approvals.view', 'admin.approvals.index'],
        ['Unit Kerja', 'Lokasi', 'location', 'locations.view', 'admin.locations.index'],
        ['Unit Kerja', 'Departemen', 'department', 'departments.view', 'admin.departments.index'],
        ['Unit Kerja', 'Jabatan', 'position', 'positions.view', 'admin.positions.index'],
        ['Kepegawaian', 'Pegawai', 'employee', 'employees.view', 'admin.employees.index'],
        ['Kepegawaian', 'Penugasan', 'assignment', 'assignments.view', 'admin.assignments.index'],
        ['Kepegawaian', 'Impor Pegawai', 'import', 'employees.import', 'admin.imports.index'],
        ['Presensi', 'Presensi Harian', 'attendance', 'attendance.view', 'admin.attendance.index'],
        ['Presensi', 'Rekap Bulanan', 'recap', 'attendance.view', 'admin.attendance-summary.index'],
        ['Presensi', 'Koreksi Presensi', 'correction', 'attendance_corrections.view', 'admin.attendance-corrections.index'],
        ['Presensi', 'Jadwal', 'schedule', 'schedules.view', 'admin.schedules.index'],
        ['Presensi', 'Shift', 'shift', 'shifts.view', 'admin.shifts.index'],
        ['Presensi', 'Hari Libur', 'holiday', 'holidays.view', 'admin.holidays.index'],
        ['Presensi', 'Tugas Luar Kota', 'trip', 'field_trips.view', 'admin.field-trips.index'],
        // Perangkat = HP Pakis Pro yang dipakai presensi; dikelola bersama alur presensi (keputusan Mas 16 Nov 2026).
        ['Presensi', 'Perangkat', 'device', 'devices.view', 'admin.devices.index'],
        ['Pengguna & Akses', 'Pengguna', 'user', 'users.view', 'admin.users.index'],
        ['Pengguna & Akses', 'Role & Hak Akses', 'role', 'roles.view', 'admin.roles.index'],
        ['Pengaturan', 'Pengaturan Umum', 'settings', 'settings.view', 'admin.settings.edit'],
        ['Pengaturan', 'Penomoran', 'sequence', 'sequences.view', 'admin.sequences.index'],
        ['Pengaturan', 'Referensi', 'reference', 'references.view', 'admin.references.index'],
        ['Pengaturan', 'Wilayah', 'region', 'regions.view', 'admin.regions.index'],
        ['Pengaturan', 'Aturan Persetujuan', 'approval_rule', 'approvals.configure', 'admin.approval-rules.index'],
        ['Audit', 'Audit Log', 'audit', 'audit.view', 'admin.audit.index'],
        ['Audit', 'Log Keamanan', 'security', 'audit.view', 'admin.security-events.index'],
    ];

    /** @return list<string> permission yang membuka setidaknya satu menu (BR-017). */
    public static function permissions(): array
    {
        return array_values(array_unique(array_column(self::ITEMS, 3)));
    }

    /**
     * @return list<array{section: string|null, title: string|null, items: list<array{title: string, href: string, icon: string, badge?: int}>}>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (self::ITEMS as [$group, $title, $icon, $permission, $route]) {
            if (! Route::has($route)) {
                continue;
            }
            $badge = self::badge($route, $user);
            // PA-27: item berangka (Persetujuan) juga tampil bagi calon penyetuju tanpa izinnya selama
            // ada yang menunggu dia — route-nya memang terbuka untuk mereka (tab "Menunggu saya").
            if (! $user->can($permission) && ! ($badge !== null && $badge > 0)) {
                continue;
            }

            $key = $group ?? '';
            $groups[$key] ??= ['section' => $group === null ? null : self::SECTIONS[$group], 'title' => $group, 'items' => []];
            // Relatif: aman dari perbedaan host APP_URL dan cocok untuk penanda item aktif.
            $item = ['title' => $title, 'href' => route($route, absolute: false), 'icon' => $icon];
            if ($badge !== null) {
                $item['badge'] = $badge;
            }
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    /**
     * Angka di samping menu (15 §6.5 "dengan angka menunggu"). Dihitung ApprovalVisibility yang sama
     * dengan tab "Menunggu saya", jadi angka menu selalu = isi tab itu.
     */
    private static function badge(string $route, User $user): ?int
    {
        return match ($route) {
            'admin.approvals.index' => app(ApprovalVisibility::class)->pendingCountFor($user),
            default => null,
        };
    }
}
