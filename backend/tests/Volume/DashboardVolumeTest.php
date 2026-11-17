<?php

use App\Models\User;
use Database\Seeders\InventoryVolumeSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * T-087A kriteria "dasbor tetap ringan: tiap kartu satu query agregat ber-index (tanpa N+1), dicek
 * dengan data volume T-087". Grup `volume` (dikecualikan dari run biasa & CI), dijalankan manual:
 *
 *   PowerShell: $env:DB_DATABASE='pakis_one_test_vol'; php artisan test tests/Volume/DashboardVolumeTest.php --group=volume
 *
 * Data VOL- (1 juta mutasi, 30.303 batch, 300 produk × 10 lokasi) dibersihkan sendiri seperti
 * StockCardVolumeTest (TANPA RefreshDatabase; `stock_movements` di-TRUNCATE, hanya di DB test).
 */

uses(TestCase::class)->group('volume');

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    dashboardVolumeCleanup();
});

afterEach(fn () => dashboardVolumeCleanup());

function dashboardVolumeCleanup(): void
{
    DB::statement('TRUNCATE TABLE stock_movements');
    $products = DB::table('products')->where('sku', 'like', InventoryVolumeSeeder::PREFIX.'%')->pluck('id');
    foreach (DB::table('stock_batches')->whereIn('product_id', $products)->pluck('id')->chunk(5000) as $chunk) {
        DB::table('stock_reservations')->whereIn('stock_batch_id', $chunk)->delete();
        DB::table('stock_batches')->whereIn('id', $chunk)->delete();
    }
    DB::table('product_stock_levels')->whereIn('product_id', $products)->delete();
    DB::table('product_costs')->whereIn('product_id', $products)->delete();
    DB::table('products')->whereIn('id', $products)->delete();
    DB::table('product_categories')->where('code', 'like', InventoryVolumeSeeder::PREFIX.'%')->delete();
    DB::table('stock_locations')->where('code', 'like', InventoryVolumeSeeder::PREFIX.'%')->delete();
    DB::table('reference_items')->where('group_code', 'unit')->where('code', 'like', strtolower(InventoryVolumeSeeder::PREFIX).'%')->delete();
}

test('T-087A PA-73 dasbor Owner dengan 1 juta mutasi: p95 < 500 ms, jumlah query tetap (tanpa N+1)', function () {
    (new InventoryVolumeSeeder)->run();
    DB::statement('ANALYZE TABLE stock_movements, stock_batches');
    (new PermissionSeeder)->run();
    (new RoleSeeder)->run();
    $owner = User::factory()->withTwoFactor()->create()->assignRole('owner');

    try {
        $url = route('admin.dashboard');
        $this->actingAs($owner)->get($url)->assertOk(); // pemanasan

        $queries = 0;
        $slow = [];
        DB::listen(function ($q) use (&$queries, &$slow) {
            $queries++;
            $key = substr(preg_replace('/\s+/', ' ', $q->sql), 0, 140);
            $slow[$key] = max($slow[$key] ?? 0, $q->time);
        });
        $times = [];
        $counts = [];
        for ($i = 0; $i < 20; $i++) {
            $queries = 0;
            $started = hrtime(true);
            $this->actingAs($owner)->get($url)->assertOk();
            $times[] = (hrtime(true) - $started) / 1e6;
            $counts[] = $queries;
        }
        sort($times);
        $p95 = $times[(int) ceil(0.95 * count($times)) - 1];

        // Grafik mutasi per hari: rentang bawaan dasbor (7 hari) atas 1 juta mutasi tidak boleh full scan.
        $explain = array_map(fn ($r) => (array) $r, DB::select(
            "EXPLAIN SELECT business_date, SUM(CASE WHEN qty > 0 THEN 1 ELSE 0 END), SUM(CASE WHEN qty < 0 THEN 1 ELSE 0 END)
             FROM stock_movements WHERE business_date BETWEEN ? AND ?
               AND stock_location_id IN (SELECT id FROM stock_locations WHERE type IN ('warehouse','store'))
             GROUP BY business_date",
            [now()->subDays(6)->toDateString(), now()->toDateString()],
        ));
        $movementScan = collect($explain)->firstWhere('table', 'stock_movements');

        fwrite(STDERR, json_encode([
            'dashboard_owner_ms' => ['p50' => round($times[9], 1), 'p95' => round($p95, 1), 'max' => round(end($times), 1)],
            'queries_per_request' => array_values(array_unique($counts)),
            'flow_explain' => "{$movementScan['type']} key={$movementScan['key']} rows={$movementScan['rows']}",
            'slowest_queries_ms' => collect($slow)->sortDesc()->take(6)->all(),
        ]).PHP_EOL);

        expect($p95)->toBeLessThan(500.0)
            ->and(array_unique($counts))->toHaveCount(1) // jumlah query tidak bergantung data (tanpa N+1)
            ->and($movementScan['type'])->not->toBe('ALL');
    } finally {
        DB::table('model_has_roles')->where('model_id', $owner->id)->delete();
        $owner->delete();
    }
});
