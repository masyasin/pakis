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
            ['code' => 'attendance_correction', 'name' => 'Nomor koreksi absen', 'pattern' => 'KOR-{YYYY}{MM}-{SEQ:4}', 'reset_period' => ResetPeriod::Monthly],
        ];

        foreach ($sequences as $sequence) {
            DocumentSequence::query()->firstOrCreate(['code' => $sequence['code']], [...$sequence, 'is_system' => true]);
        }
    }
}
