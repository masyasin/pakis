<?php

use App\Domain\Inventory\Actions\ReconcileStockLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\InventoryVolumeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * T-077 kriteria "seeder volume 1 juta mutasi; query kartu stok per batch & per produk-lokasi-periode
 * memakai index (EXPLAIN tanpa ALL)" (NFR-A02). Keputusan rencana T-077: grup `volume` DIKECUALIKAN
 * dari run biasa & CI (`<groups><exclude>` di phpunit.xml) karena seeding ±beberapa menit. Dijalankan
 * manual sekali sebelum UAT Paket A, hasilnya dicatat di Implementasi_Plan §6.4:
 *
 *   PowerShell: $env:DB_DATABASE='pakis_one_test_vol'; php artisan test --group=volume
 *   Percobaan cepat: $env:INVENTORY_VOLUME_MOVEMENTS='50000' (bawaan 1.000.000)
 *
 * TANPA RefreshDatabase (rollback 1 juta baris lambat): data VOL- dibersihkan sendiri. `stock_movements`
 * append-only, jadi dibersihkan dengan TRUNCATE (tidak memicu trigger) — hanya di DB test.
 */

uses(TestCase::class)->group('volume');

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    volumeCleanup();
});

afterEach(fn () => volumeCleanup());

function volumeCleanup(): void
{
    DB::statement('TRUNCATE TABLE stock_movements');
    $products = DB::table('products')->where('sku', 'like', InventoryVolumeSeeder::PREFIX.'%')->pluck('id');
    $batches = DB::table('stock_batches')->whereIn('product_id', $products)->pluck('id');
    foreach ($batches->chunk(5000) as $chunk) {
        DB::table('stock_reservations')->whereIn('stock_batch_id', $chunk)->delete();
        DB::table('stock_batches')->whereIn('id', $chunk)->delete();
    }
    DB::table('product_costs')->whereIn('product_id', $products)->delete();
    DB::table('products')->whereIn('id', $products)->delete();
    DB::table('product_categories')->where('code', 'like', InventoryVolumeSeeder::PREFIX.'%')->delete();
    DB::table('stock_locations')->where('code', 'like', InventoryVolumeSeeder::PREFIX.'%')->delete();
    DB::table('reference_items')->where('group_code', 'unit')->where('code', 'like', strtolower(InventoryVolumeSeeder::PREFIX).'%')->delete();
}

/** @return list<array<string, mixed>> baris EXPLAIN */
function explainRows(string $sql, array $bindings): array
{
    return array_map(fn ($r) => (array) $r, DB::select('EXPLAIN '.$sql, $bindings));
}

test('NFR-A02 1 juta mutasi: kartu stok per batch & per produk-lokasi-periode memakai index, rekonsiliasi 0 selisih', function () {
    $started = microtime(true);
    (new InventoryVolumeSeeder)->run();
    $seeded = microtime(true) - $started;
    DB::statement('ANALYZE TABLE stock_movements, stock_batches');

    $target = (int) (getenv('INVENTORY_VOLUME_MOVEMENTS') ?: 1_000_000);
    expect(DB::table('stock_movements')->count())->toBeGreaterThanOrEqual($target - 33);

    $batch = DB::table('stock_batches')->orderByDesc('id')->first();
    $cases = [
        'kartu stok per batch' => [
            'SELECT id, movement_type, qty, qty_after, business_date FROM stock_movements WHERE stock_batch_id = ? ORDER BY moved_at, id LIMIT 50',
            [$batch->id], 'smov_batch_time_idx',
        ],
        'kartu stok produk-lokasi-periode' => [
            'SELECT id, stock_batch_id, movement_type, qty, business_date FROM stock_movements WHERE product_id = ? AND stock_location_id = ? AND business_date BETWEEN ? AND ? ORDER BY business_date, id LIMIT 100',
            [$batch->product_id, $batch->stock_location_id, now()->subDays(90)->toDateString(), now()->toDateString()], 'smov_product_loc_date_idx',
        ],
        'batch per produk-lokasi (FEFO)' => [
            "SELECT id FROM stock_batches WHERE product_id = ? AND stock_location_id = ? AND status = 'available' ORDER BY expiry_date",
            [$batch->product_id, $batch->stock_location_id], 'sbatch_fefo_idx',
        ],
    ];

    $report = [];
    foreach ($cases as $name => [$sql, $bindings, $index]) {
        $rows = explainRows($sql, $bindings);
        $report[$name] = array_map(fn ($r) => "{$r['type']} key={$r['key']} rows={$r['rows']}", $rows);
        foreach ($rows as $row) {
            expect($row['type'])->not->toBe('ALL', "{$name}: full scan")
                ->and($row['key'])->toBe($index, $name);
        }
    }

    $reconcileStarted = microtime(true);
    $result = app(ReconcileStockLedger::class)->handle();
    $reconciled = microtime(true) - $reconcileStarted;
    expect($result['mismatches'])->toBe([]);

    // Ringkasan untuk log Implementasi_Plan (tampil di keluaran test).
    fwrite(STDERR, json_encode(['seed_s' => round($seeded), 'reconcile_s' => round($reconciled), 'batches' => $result['batches'], 'explain' => $report], JSON_PRETTY_PRINT).PHP_EOL);
});

test('NFR-A04 T-087 halaman Kartu Stok 1 juta mutasi, filter 1 produk 1 lokasi 1 bulan: p95 < 500 ms', function () {
    (new InventoryVolumeSeeder)->run();
    DB::statement('ANALYZE TABLE stock_movements, stock_batches');
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
    $owner = User::factory()->withTwoFactor()->create()->assignRole('owner');

    try {
        // Produk × lokasi × bulan dengan mutasi terbanyak = kasus terberat untuk filter ini.
        $busiest = DB::table('stock_movements')
            ->selectRaw("product_id, stock_location_id, DATE_FORMAT(business_date, '%Y-%m-01') AS month, COUNT(*) AS n")
            ->groupBy('product_id', 'stock_location_id')->groupByRaw("DATE_FORMAT(business_date, '%Y-%m-01')")
            ->orderByDesc('n')->first();
        $from = $busiest->month;
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        $url = "/stok/kartu?filter[product_id][]={$busiest->product_id}&filter[stock_location_id][]={$busiest->stock_location_id}"
            ."&filter[business_date][from]={$from}&filter[business_date][to]={$to}";

        $this->actingAs($owner)->get($url)->assertOk(); // pemanasan (cache rute, koneksi)
        $times = [];
        for ($i = 0; $i < 20; $i++) {
            $started = hrtime(true);
            $this->actingAs($owner)->get($url.'&page='.($i % 3 + 1))->assertOk();
            $times[] = (hrtime(true) - $started) / 1e6;
        }
        sort($times);
        $p95 = $times[(int) ceil(0.95 * count($times)) - 1];

        fwrite(STDERR, json_encode(['nfr_a04_stock_card_ms' => ['p50' => round($times[9], 1), 'p95' => round($p95, 1), 'max' => round(end($times), 1)],
            'movements_in_filter' => DB::table('stock_movements')->where('product_id', $busiest->product_id)
                ->where('stock_location_id', $busiest->stock_location_id)->whereBetween('business_date', [$from, $to])->count()]).PHP_EOL);
        expect($p95)->toBeLessThan(500.0);
    } finally {
        DB::table('model_has_roles')->where('model_id', $owner->id)->delete();
        $owner->delete();
    }
});
