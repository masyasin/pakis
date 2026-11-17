<?php

use App\Console\Commands\InventoryStress;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
 * Kriteria T-077 / NFR-A03 / SEC-102: 20 proses PHP sungguhan berebut 1 unit terakhir satu batch
 * → tepat 1 sukses, 19 INSUFFICIENT_STOCK, saldo 0, tanpa deadlock tak tertangani.
 *
 * Pola NumberGeneratorConcurrencyTest: TANPA RefreshDatabase (proses lain hanya melihat data yang
 * sudah di-commit, dan kunci baris hanya teruji bila ada transaksi lain yang bersaing). Skenario &
 * proses anak dijalankan `php artisan inventory:stress` (perintah yang sama dipakai CI), lalu data
 * uji dibersihkan di sini. `stock_movements` append-only (trigger BEFORE DELETE), jadi pembersihan
 * memakai TRUNCATE (tidak memicu trigger) — sah hanya di DB test yang tidak menyimpan data lain
 * yang di-commit; test ber-RefreshDatabase selalu di dalam transaksi yang di-rollback.
 */

uses(TestCase::class);

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    lastUnitCleanup();
});

afterEach(fn () => lastUnitCleanup());

function lastUnitCleanup(): void
{
    $products = DB::table('products')->where('sku', 'like', InventoryStress::CODE_PREFIX.'%')->pluck('id');
    $batches = DB::table('stock_batches')->whereIn('product_id', $products)->pluck('id');
    if ($batches->isNotEmpty() || DB::table('stock_movements')->where('source_type', InventoryStress::SOURCE_TYPE)->exists()) {
        DB::statement('TRUNCATE TABLE stock_movements');
    }
    DB::table('stock_reservations')->whereIn('stock_batch_id', $batches)->delete();
    DB::table('stock_batch_status_logs')->whereIn('stock_batch_id', $batches)->delete();
    DB::table('stock_batches')->whereIn('id', $batches)->delete();
    DB::table('product_costs')->whereIn('product_id', $products)->delete();
    DB::table('products')->whereIn('id', $products)->delete();
    DB::table('product_categories')->where('code', 'like', InventoryStress::CODE_PREFIX.'%')->delete();
    DB::table('stock_locations')->where('code', 'like', InventoryStress::CODE_PREFIX.'%')->delete();
    DB::table('reference_items')->where('group_code', 'unit')->where('code', 'like', strtolower(InventoryStress::CODE_PREFIX).'%')->delete();
}

test('NFR-A03 20 proses berebut 1 unit terakhir → 1 sukses, 19 INSUFFICIENT_STOCK, saldo 0', function () {
    // Proses anak memakai koneksi DB yang sama dengan test ini (DB test, bukan DB dev).
    $connection = 'database.connections.'.config('database.default');
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => config($connection.'.host'),
        'DB_PORT' => (string) config($connection.'.port'),
        'DB_DATABASE' => config($connection.'.database'),
        'DB_USERNAME' => config($connection.'.username'),
        'DB_PASSWORD' => (string) config($connection.'.password'),
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];

    $process = new Process([PHP_BINARY, base_path('artisan'), 'inventory:stress', '--workers=20', '--json'], base_path(), $env, null, 300);
    $process->run();

    $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
    $summary = json_decode((string) end($lines), true);
    expect($summary)->toBeArray($process->getOutput().$process->getErrorOutput());

    expect($summary['errors'])->toBe([])
        ->and($summary['ok'])->toBe(1)
        ->and($summary['insufficient'])->toBe(19)
        ->and($summary['conflict'])->toBe(0)
        ->and($summary['balance'])->toBe('0.000')
        ->and($summary['movements'])->toBe(2) // terima 1 + jual 1
        ->and($summary['passed'])->toBeTrue()
        ->and($process->getExitCode())->toBe(0);
});
