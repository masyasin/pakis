<?php

namespace Database\Seeders;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Position;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data DEMO (04 §4.2): hanya `local`/`staging`, DITOLAK di produksi (CLAUDE.md §6: tidak ada data
 * asli Pakis di repo). Idempoten: dijalankan ulang tidak menggandakan data, dan tidak menimpa data
 * yang sudah diubah dari layar.
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * Modul berikutnya menambah bagiannya di sini (lokasi, jabatan, pegawai, shift, …) sesuai 04 §4.2.
 */
class DemoSeeder extends Seeder
{
    /** Departemen demo 04 §4.2 (struktur datar). Kode singkat dipakai di laporan & impor. */
    public const DEPARTMENTS = [
        'PJL' => 'Penjualan',
        'GDG-LOG' => 'Gudang & Logistik',
        'TOKO' => 'Toko',
        'KEU-ADM' => 'Keuangan & Administrasi',
        'SDM' => 'SDM',
    ];

    /**
     * Jabatan demo 04 §4.2: kode => [nama, kode departemen, kode atasan, level, mode absen].
     * Urutan penting: atasan dibuat lebih dulu.
     */
    public const POSITIONS = [
        'OWNER' => ['Owner', 'KEU-ADM', null, 1, 'location'],
        'SPV-SLS' => ['Supervisor Sales', 'PJL', 'OWNER', 3, 'location'],
        'SLS' => ['Sales', 'PJL', 'SPV-SLS', 5, 'anywhere'],
        'KA-GDG' => ['Kepala Gudang', 'GDG-LOG', 'OWNER', 3, 'location'],
        'STF-GDG' => ['Staf Gudang', 'GDG-LOG', 'KA-GDG', 5, 'location'],
        'DRV' => ['Driver', 'GDG-LOG', 'KA-GDG', 5, 'any_location'],
        'KA-TKO' => ['Kepala Toko', 'TOKO', 'OWNER', 3, 'location'],
        'KSR' => ['Kasir', 'TOKO', 'KA-TKO', 5, 'location'],
        'ADM' => ['Admin', 'KEU-ADM', 'OWNER', 5, 'location'],
        'STF-HRD' => ['Staf HRD', 'SDM', 'OWNER', 5, 'location'],
    ];

    /** Shift demo 04 §4.2: [nama, masuk, pulang, toleransi telat (menit)]. */
    public const SHIFTS = [
        'PAGI' => ['Shift Pagi', '08:00:00', '16:00:00', 10],
        'GDG' => ['Shift Gudang', '07:00:00', '15:00:00', 5],
        'SLS' => ['Shift Sales', '07:30:00', '16:30:00', 15],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder ditolak di produksi: data demo hanya untuk local/staging (04 §4.2).');
        }

        foreach (self::DEPARTMENTS as $code => $name) {
            // withTrashed: kode unik juga untuk departemen yang terhapus; jangan membuat duplikat.
            Department::withTrashed()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        foreach (self::POSITIONS as $code => [$name, $department, $parent, $level, $mode]) {
            Position::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'department_id' => Department::withTrashed()->where('code', $department)->value('id'),
                'parent_position_id' => $parent === null ? null : Position::withTrashed()->where('code', $parent)->value('id'),
                'level' => $level,
                'attendance_mode' => $mode,
            ]);
        }

        foreach (self::SHIFTS as $code => [$name, $start, $end, $tolerance]) {
            Shift::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_min' => $tolerance,
            ]);
        }
    }
}
