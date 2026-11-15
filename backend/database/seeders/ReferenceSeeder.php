<?php

namespace Database\Seeders;

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Database\Seeder;

/**
 * Item referensi sistem (04 §4.1, 08 §3). Wajib di semua lingkungan dan idempoten: item yang sudah
 * ada tidak ditimpa, sehingga nama/urutan/status yang diubah admin tetap.
 */
class ReferenceSeeder extends Seeder
{
    /** @var array<string, array<string, string>> grup => [kode => nama] */
    private const ITEMS = [
        'location_type' => [
            'office' => 'Kantor',
            'warehouse' => 'Gudang',
            'store' => 'Toko',
            'other' => 'Lainnya',
        ],
        'employment_status' => [
            'permanent' => 'Tetap',
            'contract' => 'Kontrak',
            'probation' => 'Percobaan',
            'daily' => 'Harian Lepas',
        ],
        'correction_reason' => [
            'forgot' => 'Lupa presensi',
            'gps_error' => 'GPS/HP bermasalah',
            'field_duty' => 'Tugas luar',
            'system_error' => 'Gangguan sistem',
            // Presensi manual oleh admin/atasan (keputusan Mas 16 Nov 2026, BR-069).
            'phone_broken' => 'HP rusak',
            'phone_lost' => 'HP hilang',
            'phone_left' => 'HP ketinggalan',
            'other' => 'Lainnya',
        ],
        'offboarding_reason' => [
            'resign' => 'Mengundurkan diri',
            'contract_end' => 'Kontrak berakhir',
            'terminated' => 'Diberhentikan',
            'retired' => 'Pensiun',
            'deceased' => 'Meninggal dunia',
            'other' => 'Lainnya',
        ],
    ];

    public function run(): void
    {
        foreach (ReferenceGroup::cases() as $group) {
            $order = 0;
            foreach (self::ITEMS[$group->value] as $code => $name) {
                $order += 10;
                ReferenceItem::query()->firstOrCreate(
                    ['group_code' => $group->value, 'code' => $code],
                    ['name' => $name, 'sort_order' => $order, 'is_active' => true, 'is_system' => true],
                );
            }
        }
    }
}
