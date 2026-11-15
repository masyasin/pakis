<?php

namespace Database\Seeders;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Organization\Models\Department;
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

        foreach (self::SHIFTS as $code => [$name, $start, $end, $tolerance]) {
            Shift::withTrashed()->firstOrCreate(['code' => $code], [
                'name' => $name, 'start_time' => $start, 'end_time' => $end, 'late_tolerance_min' => $tolerance,
            ]);
        }
    }
}
