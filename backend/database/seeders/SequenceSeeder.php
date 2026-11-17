<?php

namespace Database\Seeders;

use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\Models\DocumentSequence;
use Illuminate\Database\Seeder;

/**
 * Urutan penomoran sistem (04 §4). Dibutuhkan di SEMUA lingkungan, termasuk produksi.
 * Idempoten: pola yang sudah diubah admin tidak ditimpa.
 */
class SequenceSeeder extends Seeder
{
    public function run(): void
    {
        $sequences = [
            ['code' => 'employee', 'name' => 'Nomor pegawai', 'pattern' => 'PJA-{YY}{SEQ:4}', 'reset_period' => ResetPeriod::Never],
            ['code' => 'attendance_correction', 'name' => 'Nomor koreksi presensi', 'pattern' => 'KOR-{YYYY}{MM}-{SEQ:4}', 'reset_period' => ResetPeriod::Monthly],
            ['code' => 'field_trip', 'name' => 'Nomor tugas luar kota', 'pattern' => 'DL/{YYYY}/{MM}/{SEQ:4}', 'reset_period' => ResetPeriod::Monthly],
            // Paket A (14 §8): SKU otomatis bila `product.sku_mode = auto` (T-070).
            ['code' => 'product', 'name' => 'Kode produk (SKU otomatis)', 'pattern' => 'PRD-{SEQ:5}', 'reset_period' => ResetPeriod::Never],
            // Paket A (14 §8): kode supplier otomatis (T-072).
            ['code' => 'supplier', 'name' => 'Kode supplier', 'pattern' => 'SUP-{SEQ:4}', 'reset_period' => ResetPeriod::Never],
            // Paket A (14 §8): nomor purchase order, reset bulanan (T-079).
            ['code' => 'purchase_order', 'name' => 'Nomor purchase order', 'pattern' => 'PO-{YY}{MM}-{SEQ:4}', 'reset_period' => ResetPeriod::Monthly],
            // Paket A (14 §8): kode kios bila `customer.code_mode = auto` (T-073).
            ['code' => 'customer', 'name' => 'Kode pelanggan (kios)', 'pattern' => 'KIO-{SEQ:5}', 'reset_period' => ResetPeriod::Never],
        ];

        foreach ($sequences as $sequence) {
            DocumentSequence::query()->firstOrCreate(['code' => $sequence['code']], [...$sequence, 'is_system' => true]);
        }
    }
}
