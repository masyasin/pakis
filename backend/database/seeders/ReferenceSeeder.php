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
        // Paket A (15 §4.9, T-080): alasan barang ditolak di penerimaan (klaim ke supplier).
        'receipt_reject_reason' => [
            'damaged' => 'Rusak / bocor',
            'wrong_product' => 'Salah produk',
            'packaging' => 'Kemasan tidak sesuai',
            'short_delivery' => 'Jumlah kurang dari surat jalan',
            'other' => 'Lainnya',
        ],
        // Paket A (15 §4.1, T-081): alasan retur ke prinsipal.
        'purchase_return_reason' => [
            'damaged' => 'Rusak',
            'near_expiry' => 'Mendekati ED',
            'recall' => 'Penarikan prinsipal',
            'wrong_item' => 'Barang salah',
            'other' => 'Lainnya',
        ],
        // Paket A (15 §2, T-083): alasan selisih stock opname (BR-162(5)).
        'opname_variance_reason' => [
            'miscount' => 'Salah hitung sebelumnya',
            'damaged' => 'Rusak tak tercatat',
            'lost' => 'Hilang',
            'unrecorded_transaction' => 'Transaksi belum dicatat',
            'other' => 'Lainnya',
        ],
        // Paket A (15 §2, T-084): alasan karantina manual (BR-164) & pemusnahan (BR-163).
        'quarantine_reason' => [
            'damaged' => 'Rusak',
            'leaking' => 'Bocor/rembes',
            'suspect' => 'Diduga tidak sesuai',
            'expired' => 'Kedaluwarsa',
            'recall' => 'Penarikan',
            'other' => 'Lainnya',
        ],
        'disposal_reason' => [
            'expired' => 'Kedaluwarsa',
            'damaged' => 'Rusak',
            'recall' => 'Penarikan',
            'other' => 'Lainnya',
        ],
        // Paket A (15 §4.1, T-069).
        'unit' => [
            'bottle' => 'Botol',
            'box' => 'Dus',
            'sachet' => 'Sachet',
            'pcs' => 'Pcs',
            'pack' => 'Pak',
            'kg' => 'Kg',
            'gram' => 'Gram',
            'liter' => 'Liter',
            'ml' => 'mL',
            'sack' => 'Karung',
            'jerrycan' => 'Jeriken',
            'unit' => 'Unit',
        ],
        'formulation' => [
            'ec' => 'EC',
            'wp' => 'WP',
            'sl' => 'SL',
            'sc' => 'SC',
            'wg' => 'WG',
            'gr' => 'GR',
            'od' => 'OD',
            'cs' => 'CS',
            'other' => 'Lainnya',
        ],
        // Paket A (15 §4.1, T-073).
        'customer_type' => [
            'kiosk' => 'Kios',
            'retail' => 'Toko eceran',
            'farmer_group' => 'Kelompok tani',
            'project' => 'Proyek/instansi',
            'other' => 'Lainnya',
        ],
    ];

    /**
     * Meta item sistem: satuan yang hanya menerima bilangan bulat (BR-150, `QTY_NOT_INTEGER`).
     * Kg, gram, liter, dan mL boleh pecahan.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const META = [
        'unit' => [
            'bottle' => ['integer_only' => true],
            'box' => ['integer_only' => true],
            'sachet' => ['integer_only' => true],
            'pcs' => ['integer_only' => true],
            'pack' => ['integer_only' => true],
            'sack' => ['integer_only' => true],
            'jerrycan' => ['integer_only' => true],
            'unit' => ['integer_only' => true],
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
                    ['name' => $name, 'sort_order' => $order, 'is_active' => true, 'is_system' => true, 'meta' => self::META[$group->value][$code] ?? null],
                );
            }
        }
    }
}
