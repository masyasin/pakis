<?php

use App\Domain\Inventory\Actions\ExpireBatches;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchStatusLog;
use App\Domain\Inventory\Notifications\BatchExpiredNotification;
use App\Domain\Notification\Enums\NotificationKind;
use App\Models\User;
use App\Support\Money\Qty;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeOrgStructure;
use Tests\Support\LedgerWorld;

/*
 * T-078 — kedaluwarsa otomatis (BR-157, 15 §11.1 ExpireBatches, §9.1 `batch_expired`). Data dummy.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    // 00.15 WIB tanggal 17 Nov = 16 Nov 17.15 UTC: "hari ini" harus tanggal lokal.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 17:15:00', 'UTC'));
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->product = LedgerWorld::product('P-01');
    Notification::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function expBatch(object $test, string $no, ?string $expiry, ?int $binId = null, ?object $location = null): StockBatch
{
    return LedgerWorld::receive($location ?? $test->gd, $test->product, '10', '1000', new BatchKey($no, $expiry, $binId), '2026-01-10');
}

test('BR-157 job ED: batch ED kemarin & hari ini → expired, reservasi aktif dilepas, log status user NULL', function () {
    $yesterday = expBatch($this, 'KMR', '2026-11-16');
    $today = expBatch($this, 'HRI', '2026-11-17');
    $tomorrow = expBatch($this, 'BSK', '2026-11-18');
    $noExpiry = expBatch($this, 'TNP', null);
    $reservation = StockLedger::transaction(fn () => LedgerWorld::ledger()->reserve($yesterday, Qty::of('4'), LedgerWorld::source('stock_transfer', 7)));

    $result = app(ExpireBatches::class)->handle();

    expect($result)->toBe(['expired' => 2, 'locations' => 1])
        ->and($yesterday->fresh()->status)->toBe(StockBatchStatus::Expired)
        ->and((string) $yesterday->fresh()->qty_reserved)->toBe('0.000')
        ->and((string) $yesterday->fresh()->qty_on_hand)->toBe('10.000')
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Released)
        ->and($today->fresh()->status)->toBe(StockBatchStatus::Expired)
        ->and($tomorrow->fresh()->status)->toBe(StockBatchStatus::Available)
        ->and($noExpiry->fresh()->status)->toBe(StockBatchStatus::Available);

    $log = StockBatchStatusLog::query()->where('stock_batch_id', $yesterday->id)->sole();
    expect($log->from_status)->toBe('available')
        ->and($log->to_status)->toBe('expired')
        ->and($log->user_id)->toBeNull()
        ->and($log->note)->toBe(ExpireBatches::NOTE);
});

test('BR-157 job ED dijalankan dua kali tidak menggandakan log maupun notifikasi', function () {
    expBatch($this, 'KMR', '2026-11-16');
    $owner = User::factory()->create()->assignRole('owner');

    app(ExpireBatches::class)->handle();
    $second = app(ExpireBatches::class)->handle();
    $this->artisan('inventory:expire-batches')->assertSuccessful();

    expect($second)->toBe(['expired' => 0, 'locations' => 0])
        ->and(StockBatchStatusLog::query()->count())->toBe(1);
    Notification::assertSentToTimes($owner, BatchExpiredNotification::class, 1);
});

test('BR-157 batch karantina ber-ED lewat tidak diubah job (hanya available → expired)', function () {
    $quarantined = expBatch($this, 'KAR', '2026-11-10', LedgerWorld::quarantineBin($this->gd)->id);

    app(ExpireBatches::class)->handle();

    expect($quarantined->fresh()->status)->toBe(StockBatchStatus::Quarantine)
        ->and(StockBatchStatusLog::query()->count())->toBe(0);
});

test('BR-157 notifikasi ringkas per lokasi ke kepala gudang lokasi terkait; lokasi tanpa kepala gudang → Owner (PA-64)', function () {
    $other = LedgerWorld::warehouse('GD-B');
    FakeOrgStructure::bind()->employee(1)->at($this->gd->location_id);
    $head = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');
    $owner = User::factory()->create()->assignRole('owner');
    User::factory()->create(['is_active' => false])->assignRole('owner');

    expBatch($this, 'A1', '2026-11-15');
    expBatch($this, 'A2', '2026-11-16');
    expBatch($this, 'B1', '2026-11-16', location: $other);

    app(ExpireBatches::class)->handle();

    Notification::assertSentToTimes($head, BatchExpiredNotification::class, 1);
    Notification::assertSentTo($head, BatchExpiredNotification::class, function (BatchExpiredNotification $n) {
        return $n->kind() === NotificationKind::BatchExpired
            && $n->title() === '2 batch kedaluwarsa di Stok GD-A'
            && str_contains($n->body(), 'Produk P-01 (A1)') && str_contains($n->body(), 'Produk P-01 (A2)');
    });
    // GD-B tanpa kepala gudang → Owner aktif saja; Owner tidak menerima ringkasan GD-A.
    Notification::assertSentToTimes($owner, BatchExpiredNotification::class, 1);
    Notification::assertSentTo($owner, BatchExpiredNotification::class, fn (BatchExpiredNotification $n) => $n->title() === '1 batch kedaluwarsa di Stok GD-B');
});

test('15 §11.1 job ED 00.15 & alert ED 06.00 waktu lokal, satu server, tanpa tumpang tindih', function () {
    $events = collect(app(Schedule::class)->events())
        ->mapWithKeys(fn ($e) => [trim((string) preg_replace('/^.*artisan["\']?\s+/', '', (string) $e->command)) => $e]);

    foreach (['inventory:expire-batches' => '15 0 * * *', 'inventory:near-expiry-alerts' => '0 6 * * *'] as $command => $cron) {
        expect($events->get($command)?->expression)->toBe($cron, $command)
            ->and($events->get($command)?->timezone)->toBe('Asia/Jakarta')
            ->and($events->get($command)?->onOneServer)->toBeTrue()
            ->and($events->get($command)?->withoutOverlapping)->toBeTrue();
    }
});
