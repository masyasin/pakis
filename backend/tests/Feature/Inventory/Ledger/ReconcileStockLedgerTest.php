<?php

use App\Domain\Inventory\Actions\ReconcileStockLedger;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\Exceptions\InsufficientStock;
use App\Domain\Inventory\Ledger\LedgerResult;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Notifications\StockReconcileMismatchNotification;
use App\Domain\Notification\Enums\NotificationKind;
use App\Models\User;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

/*
 * T-077 — rekonsiliasi buku stok (BR-154, 15 §11.1, §9.1 `stock_reconcile_mismatch`). Data dummy.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 18:00:00', 'UTC')); // 01.00 WIB
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('BR-154 1.000 mutasi acak (terima, jual, pindah, sesuaikan, reservasi) → rekonsiliasi 0 selisih, qty_after berantai', function () {
    mt_srand(77);
    $ledger = LedgerWorld::ledger();
    $locations = [LedgerWorld::warehouse('GD-A'), LedgerWorld::warehouse('GD-B'), StockWorld::transit()];
    $products = [LedgerWorld::product('P-01'), LedgerWorld::product('P-02')];
    $source = LedgerWorld::source('uji_acak', 1);
    $reservations = [];

    $written = 0;
    while ($written < 1000) {
        $batches = StockBatch::query()->where('qty_on_hand', '>', 0)->get();
        $op = $batches->isEmpty() ? 0 : mt_rand(0, 5);
        try {
            $result = match ($op) {
                0 => $ledger->receive($locations[mt_rand(0, 1)], $products[mt_rand(0, 1)], new BatchKey('B-'.mt_rand(1, 4), '2027-12-31'),
                    Qty::of(mt_rand(1, 50).'.'.mt_rand(0, 999)), UnitCost::of(mt_rand(500, 1500).'.'.mt_rand(0, 9999)), $source, LedgerWorld::DATE),
                1 => $ledger->issue($batches->random(), Qty::of(mt_rand(1, 20)), $source, MovementType::Sale, LedgerWorld::DATE),
                2 => $ledger->move($b = $batches->random(), $locations[($b->stock_location_id === $locations[0]->id) ? 2 : 0], Qty::of(mt_rand(1, 10)), $source, MovementType::TransferOut, MovementType::TransferIn, LedgerWorld::DATE),
                3 => $ledger->adjust($batches->random(), Qty::of((mt_rand(0, 1) ? '' : '-').mt_rand(1, 5)), $source, null, LedgerWorld::DATE),
                4 => $reservations[] = $ledger->reserve($batches->random(), Qty::of(mt_rand(1, 5)), $source),
                default => ($r = array_pop($reservations)) !== null
                    ? (mt_rand(0, 1) ? $ledger->consume($r, MovementType::TransferOut, LedgerWorld::DATE) : $ledger->release($r))
                    : null,
            };
        } catch (InsufficientStock) {
            continue;
        }
        if ($result instanceof LedgerResult) {
            $written += count($result->movements);
        }
    }

    $report = app(ReconcileStockLedger::class)->handle();
    expect(StockMovement::query()->count())->toBeGreaterThanOrEqual(1000)
        ->and($report['mismatches'])->toBe([])
        ->and($report['notified'])->toBeFalse();

    // qty_after berantai: saldo sebelum + qty = qty_after untuk setiap mutasi setiap batch.
    foreach (StockBatch::query()->pluck('id') as $batchId) {
        $running = Qty::zero();
        foreach (StockMovement::query()->where('stock_batch_id', $batchId)->orderBy('id')->get() as $m) {
            $running = $running->plus($m->qty);
            expect((string) $running)->toBe($m->qty_after);
        }
    }
});

test('BR-154 selisih saldo → notifikasi ke Owner & super_admin (lonceng), tanpa koreksi; sekali per hari', function () {
    Notification::fake();
    $owner = User::factory()->withTwoFactor()->create()->assignRole('owner');
    $super = User::factory()->withTwoFactor()->create()->assignRole('super_admin');
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');

    $gd = LedgerWorld::warehouse('GD-A');
    $product = LedgerWorld::product('P-01');
    $ok = LedgerWorld::receive($gd, $product, '5');
    $bad = LedgerWorld::receive($gd, $product, '3', key: new BatchKey('B-RUSAK'));
    LedgerWorld::ledger()->reserve($ok, Qty::of(1), LedgerWorld::source());
    // Kerusakan data di luar ledger (mis. perbaikan SQL manual yang salah). Trigger tidak menjaga stock_batches.
    DB::statement('UPDATE stock_batches SET qty_on_hand = 2, qty_reserved = 0 WHERE id = ?', [$bad->id]);

    $report = app(ReconcileStockLedger::class)->handle();

    expect(collect($report['mismatches'])->map(fn ($m) => [$m['stock_batch_id'], $m['check']])->all())->toBe([[$bad->id, 'sum'], [$bad->id, 'last']])
        ->and($report['notified'])->toBeTrue()
        ->and($bad->fresh()->qty_on_hand)->toBe('2.000');

    Notification::assertSentTo([$owner, $super], StockReconcileMismatchNotification::class, function ($n, $channels) {
        return $n->kind() === NotificationKind::StockReconcileMismatch && $channels === ['database'];
    });
    Notification::assertNotSentTo($admin, StockReconcileMismatchNotification::class);
    Notification::assertSentTimes(StockReconcileMismatchNotification::class, 2);

    // Jalan ulang hari yang sama dengan selisih yang sama: tidak dikirim lagi.
    $this->artisan('inventory:reconcile')->assertSuccessful();
    Notification::assertSentTimes(StockReconcileMismatchNotification::class, 2);
});

test('BR-155 rekonsiliasi juga mencocokkan qty_reserved dengan reservasi aktif', function () {
    Notification::fake();
    $batch = LedgerWorld::receive(LedgerWorld::warehouse('GD-A'), LedgerWorld::product('P-01'), '5');
    $res = LedgerWorld::ledger()->reserve($batch, Qty::of(2), LedgerWorld::source());
    DB::table('stock_reservations')->where('id', $res->id)->update(['status' => 'released']);

    expect(app(ReconcileStockLedger::class)->handle()['mismatches'])->toBe([
        ['stock_batch_id' => $batch->id, 'check' => 'reserved', 'expected' => '2.000', 'actual' => '0.000'],
    ]);
    expect(StockReservation::query()->sole()->batch->status)->toBe(StockBatchStatus::Available);
});
