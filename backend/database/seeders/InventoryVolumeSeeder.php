<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data volume buku stok untuk uji index kartu stok (T-077, NFR-A02): bawaan 1 juta mutasi di ±30.000
 * batch (300 produk × 10 lokasi stok), tanggal bisnis tersebar 2 tahun. HANYA env `testing` (ditolak
 * di produksi & dev) karena menulis langsung ke tabel buku stok tanpa StockLedger demi kecepatan;
 * saldo tetap konsisten (qty_after berantai, saldo batch = Σ mutasi) sehingga rekonsiliasi = 0 selisih.
 *
 * Jumlah mutasi bisa diatur env `INVENTORY_VOLUME_MOVEMENTS` (mis. 50000 untuk percobaan cepat).
 * Pemakai: tests/Volume/StockCardVolumeTest.php (`php artisan test --group=volume`).
 */
class InventoryVolumeSeeder extends Seeder
{
    public const PREFIX = 'VOL-';

    public const SOURCE_TYPE = 'volume_seed';

    private const PRODUCTS = 300;

    private const LOCATIONS = 10;

    private const MOVEMENTS_PER_BATCH = 33;

    private const DAYS = 730;

    /** Baris per INSERT: 16 kolom × 2.000 < batas 65.535 placeholder PDO. */
    private const CHUNK = 2000;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('InventoryVolumeSeeder hanya untuk env testing.');
        }

        $target = (int) (getenv('INVENTORY_VOLUME_MOVEMENTS') ?: 1_000_000);
        $batchCount = max(1, intdiv($target, self::MOVEMENTS_PER_BATCH));
        DB::connection()->disableQueryLog();
        $now = now();

        $unitId = (int) DB::table('reference_items')->insertGetId([
            'group_code' => 'unit', 'code' => strtolower(self::PREFIX).'unit', 'name' => 'Unit volume', 'is_active' => true,
            'is_system' => false, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $categoryId = (int) DB::table('product_categories')->insertGetId([
            'code' => self::PREFIX.'CAT', 'name' => 'Kategori volume', 'kind' => 'other', 'requires_batch' => true, 'requires_expiry' => true,
            'requires_serial' => false, 'requires_registration' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $products = [];
        foreach (range(1, self::PRODUCTS) as $i) {
            $products[] = (int) DB::table('products')->insertGetId([
                'sku' => sprintf('%sP%04d', self::PREFIX, $i), 'name' => "Produk volume {$i}", 'product_category_id' => $categoryId,
                'base_unit_id' => $unitId, 'tracks_batch' => true, 'tracks_expiry' => true, 'tracks_serial' => false,
                'requires_registration' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $locations = [];
        foreach (range(1, self::LOCATIONS) as $i) {
            // `in_transit` tidak butuh lokasi Fase 0 maupun rak (sl_type_chk); cukup untuk distribusi index.
            $locations[] = (int) DB::table('stock_locations')->insertGetId([
                'code' => sprintf('%sL%02d', self::PREFIX, $i), 'name' => "Lokasi volume {$i}", 'type' => 'in_transit',
                'is_sellable' => false, 'is_system' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $per = self::MOVEMENTS_PER_BATCH;
        $start = $now->copy()->startOfDay()->subDays(self::DAYS);
        $batchRows = [];
        $movementRows = [];
        for ($b = 0; $b < $batchCount; $b++) {
            $product = $products[$b % self::PRODUCTS];
            $location = $locations[intdiv($b, self::PRODUCTS) % self::LOCATIONS];
            $batchRows[] = [
                'stock_location_id' => $location, 'product_id' => $product, 'batch_no' => 'V'.$b,
                'expiry_date' => $start->copy()->addDays(self::DAYS + 365 + ($b % 365))->toDateString(),
                'received_date' => $start->toDateString(), 'receipt_unit_cost' => '1000.0000',
                // Terima 100 lalu (per − 1) kali keluar 1 → saldo akhir.
                'qty_on_hand' => (100 - ($per - 1)).'.000', 'qty_reserved' => '0.000', 'status' => 'available',
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($batchRows) === self::CHUNK || $b === $batchCount - 1) {
                $firstId = $this->insertBatches($batchRows);
                foreach ($batchRows as $offset => $row) {
                    for ($m = 0; $m < $per; $m++) {
                        $day = $start->copy()->addDays(intdiv($m * self::DAYS, $per));
                        $movementRows[] = [
                            'stock_batch_id' => $firstId + $offset, 'product_id' => $row['product_id'], 'stock_location_id' => $row['stock_location_id'],
                            'movement_type' => $m === 0 ? 'receipt' : 'sale', 'qty' => $m === 0 ? '100.000' : '-1.000',
                            'qty_after' => (100 - $m).'.000', 'unit_cost' => '1000.0000', 'source_type' => self::SOURCE_TYPE,
                            'source_id' => $firstId + $offset, 'source_line_id' => null, 'business_date' => $day->toDateString(),
                            'moved_at' => $day->copy()->addSeconds($m)->format('Y-m-d H:i:s.v'), 'user_id' => null, 'note' => null,
                        ];
                        if (count($movementRows) === self::CHUNK) {
                            DB::table('stock_movements')->insert($movementRows);
                            $movementRows = [];
                        }
                    }
                }
                $batchRows = [];
            }
        }
        if ($movementRows !== []) {
            DB::table('stock_movements')->insert($movementRows);
        }
    }

    /**
     * Sisipkan satu potongan batch dan kembalikan id baris pertama. Satu INSERT multi-baris di
     * InnoDB (auto_increment_lock_mode bawaan) memberi id berurutan; dicek ulang agar tidak menebak.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertBatches(array $rows): int
    {
        DB::table('stock_batches')->insert($rows);
        $first = DB::table('stock_batches')->where('stock_location_id', $rows[0]['stock_location_id'])->where('product_id', $rows[0]['product_id'])
            ->where('batch_no', $rows[0]['batch_no'])->value('id');
        $last = DB::table('stock_batches')->where('batch_no', $rows[count($rows) - 1]['batch_no'])
            ->where('product_id', $rows[count($rows) - 1]['product_id'])->value('id');
        if ((int) $last - (int) $first !== count($rows) - 1) {
            throw new RuntimeException('Id batch volume tidak berurutan; jalankan pada DB tanpa penulis lain.');
        }

        return (int) $first;
    }
}
