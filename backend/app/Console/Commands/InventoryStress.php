<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\Exceptions\InsufficientStock;
use App\Domain\Inventory\Ledger\Exceptions\StockConflict;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\Source;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use App\Support\Time\DisplayTime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Uji konkurensi NFR-A03 / SEC-102 (T-077): N proses PHP sungguhan berebut 1 unit terakhir satu
 * batch. Lulus = tepat 1 sukses, N−1 `INSUFFICIENT_STOCK`, saldo akhir 0, tanpa galat lain
 * (deadlock tak tertangani, CHECK DB, dsb.). Pest `--parallel` tidak dipakai karena tiap proses Pest
 * punya DB sendiri, jadi tidak pernah berebut baris yang sama.
 *
 * HANYA untuk env `testing` atau CI: membuat produk/lokasi stok dummy berawalan `STRESS-` di DB aktif
 * dan tidak menghapusnya (mutasi append-only). `tests/Concurrency/LastUnitTest.php` memanggil
 * perintah ini lalu membersihkan DB test-nya.
 */
#[Signature('inventory:stress {--workers=20 : Jumlah proses paralel} {--json : Cetak ringkasan JSON} {--take= : (internal) id batch yang diambil proses anak} {--at= : (internal) waktu mulai serentak, detik unix}')]
#[Description('Uji konkurensi buku stok: banyak proses berebut 1 unit terakhir (hanya testing/CI)')]
class InventoryStress extends Command
{
    public const SOURCE_TYPE = 'stress_test';

    public const CODE_PREFIX = 'STRESS-';

    public function handle(StockLedger $ledger): int
    {
        if (! app()->environment('testing') && ! filter_var(getenv('CI'), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('inventory:stress hanya boleh dijalankan di env testing atau CI.');

            return self::FAILURE;
        }

        $take = $this->option('take');
        if (is_string($take) && $take !== '') {
            return $this->worker($ledger, (int) $take, (string) $this->option('at'));
        }

        $workers = max(2, (int) $this->option('workers'));
        $scenario = $this->scenario($ledger);

        // Waktu mulai serentak: proses anak butuh waktu boot; semua menunggu titik yang sama agar
        // benar-benar berebut kunci baris, bukan berurutan.
        $at = sprintf('%.3f', microtime(true) + 3 + $workers * 0.25);
        $processes = [];
        foreach (range(1, $workers) as $i) {
            $process = new Process([PHP_BINARY, base_path('artisan'), 'inventory:stress', '--take='.$scenario['batch_id'], '--at='.$at], base_path(), null, null, 180);
            $process->start();
            $processes[] = $process;
        }

        $outcomes = ['ok' => 0, 'insufficient' => 0, 'conflict' => 0, 'errors' => []];
        foreach ($processes as $process) {
            $process->wait();
            $line = trim($process->getOutput());
            match (true) {
                $line === 'OK' => $outcomes['ok']++,
                $line === 'INSUFFICIENT_STOCK' => $outcomes['insufficient']++,
                $line === 'STATE_CONFLICT' => $outcomes['conflict']++,
                default => $outcomes['errors'][] = $line !== '' ? $line : trim($process->getErrorOutput()),
            };
        }

        $batch = StockBatch::query()->findOrFail($scenario['batch_id']);
        $summary = [...$scenario, 'workers' => $workers, ...$outcomes,
            'balance' => (string) $batch->onHand(),
            'movements' => DB::table('stock_movements')->where('stock_batch_id', $batch->id)->count(),
        ];
        $passed = $outcomes['ok'] === 1 && $outcomes['insufficient'] === $workers - 1 && $outcomes['errors'] === []
            && $outcomes['conflict'] === 0 && $batch->onHand()->isZero();

        if ($this->option('json')) {
            $this->line(json_encode([...$summary, 'passed' => $passed], JSON_THROW_ON_ERROR));
        } else {
            $this->info("Sukses {$outcomes['ok']} · INSUFFICIENT_STOCK {$outcomes['insufficient']} · STATE_CONFLICT {$outcomes['conflict']} · galat ".count($outcomes['errors'])." · saldo {$summary['balance']}");
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function worker(StockLedger $ledger, int $batchId, string $at): int
    {
        $wait = (float) $at - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        try {
            StockLedger::transaction(fn () => $ledger->issue(StockBatch::query()->findOrFail($batchId), Qty::of(1), new Source(self::SOURCE_TYPE, 1), MovementType::Sale, DisplayTime::toLocal(now())->toDateString()));
            $this->line('OK');
        } catch (InsufficientStock) {
            $this->line('INSUFFICIENT_STOCK');
        } catch (StockConflict) {
            $this->line('STATE_CONFLICT');
        } catch (Throwable $e) {
            $this->line('ERROR '.$e::class.': '.Str::limit($e->getMessage(), 200));
        }

        return self::SUCCESS;
    }

    /**
     * Produk & lokasi stok dummy + batch berisi tepat 1 unit, lewat StockLedger.
     *
     * @return array{batch_id: int, product_id: int, stock_location_id: int, product_category_id: int, unit_id: int}
     */
    private function scenario(StockLedger $ledger): array
    {
        $code = self::CODE_PREFIX.strtoupper(Str::random(8));
        $now = now();

        return DB::transaction(function () use ($ledger, $code, $now) {
            $unitId = (int) DB::table('reference_items')->insertGetId([
                'group_code' => 'unit', 'code' => strtolower($code), 'name' => 'Unit uji', 'is_active' => true, 'is_system' => false,
                'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $categoryId = (int) DB::table('product_categories')->insertGetId([
                'code' => $code, 'name' => 'Kategori uji konkurensi', 'kind' => 'other', 'requires_batch' => false, 'requires_expiry' => false,
                'requires_serial' => false, 'requires_registration' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $product = tap((new Product)->forceFill([
                'sku' => $code, 'name' => 'Produk uji konkurensi', 'product_category_id' => $categoryId, 'base_unit_id' => $unitId,
                'tracks_batch' => false, 'tracks_expiry' => false, 'tracks_serial' => false, 'requires_registration' => false,
                'is_restricted' => false, 'is_sellable' => true, 'is_purchasable' => true, 'status' => 'active',
            ]))->save();
            // Lokasi stok tipe `in_transit` tidak butuh lokasi Fase 0 maupun rak (sl_type_chk).
            $location = tap((new StockLocation)->forceFill([
                'code' => $code, 'name' => 'Lokasi uji konkurensi', 'type' => 'in_transit', 'is_sellable' => false, 'is_system' => false, 'is_active' => true,
            ]))->save();

            $result = StockLedger::transaction(fn () => $ledger->receive(
                $location, $product, BatchKey::none(), Qty::of(1), UnitCost::of('1000'), new Source(self::SOURCE_TYPE, 1), DisplayTime::toLocal($now)->toDateString(),
            ));

            return [
                'batch_id' => $result->movement()->stock_batch_id,
                'product_id' => $product->id,
                'stock_location_id' => $location->id,
                'product_category_id' => $categoryId,
                'unit_id' => $unitId,
            ];
        });
    }
}
