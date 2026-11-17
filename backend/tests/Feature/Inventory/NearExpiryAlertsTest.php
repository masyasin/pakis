<?php

use App\Domain\Inventory\Actions\NearExpiryAlerts;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Notifications\BatchNearExpiryNotification;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Settings\Actions\UpdateSettingGroup;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingType;
use App\Models\User;
use App\Support\Money\Qty;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOrgStructure;
use Tests\Support\LedgerWorld;

/*
 * T-078 — alert mendekati ED (BR-157, 15 §11.1 NearExpiryAlerts, §9 setting, §9.1 `batch_near_expiry`)
 * dan widget dasbor "Stok mendekati ED" (15 §7.4). Data dummy.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-16 23:00:00', 'UTC')); // 06.00 WIB 17 Nov
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->product = LedgerWorld::product('P-01');
    $this->owner = User::factory()->withTwoFactor()->create()->assignRole('owner');
    Notification::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function nearBatch(object $test, string $no, string $expiry, string $qty = '10', ?object $location = null, ?int $binId = null): StockBatch
{
    return LedgerWorld::receive($location ?? $test->gd, $test->product, $qty, '1500', new BatchKey($no, $expiry, $binId), '2026-01-10');
}

/** @return list<string> */
function nearKeys(StockBatch $batch): array
{
    return DB::table('reminder_logs')->where('subject_type', NearExpiryAlerts::SUBJECT)->where('subject_id', $batch->id)
        ->orderBy('reminder_key')->pluck('reminder_key')->all();
}

test('BR-157 alert per ambang terkirim sekali per batch; ambang berikutnya terkirim saat tiba', function () {
    $batch = nearBatch($this, 'B75', '2027-01-31'); // 75 hari lagi → ambang 90

    expect(app(NearExpiryAlerts::class)->handle())->toBe(['checked' => 1, 'sent' => 1])
        ->and(nearKeys($batch))->toBe(['ed90']);
    expect(app(NearExpiryAlerts::class)->handle()['sent'])->toBe(0);
    $this->artisan('inventory:near-expiry-alerts')->assertSuccessful();
    Notification::assertSentToTimes($this->owner, BatchNearExpiryNotification::class, 1);

    // 20 hari kemudian: sisa 55 hari → ambang 60, sekali.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-06 23:00:00', 'UTC'));
    app(NearExpiryAlerts::class)->handle();
    app(NearExpiryAlerts::class)->handle();

    expect(nearKeys($batch))->toBe(['ed60', 'ed90']);
    Notification::assertSentToTimes($this->owner, BatchNearExpiryNotification::class, 2);
    Notification::assertSentTo($this->owner, BatchNearExpiryNotification::class, fn (BatchNearExpiryNotification $n) => $n->kind() === NotificationKind::BatchNearExpiry
        && $n->title() === 'ED 55 hari lagi: Produk P-01' && str_contains($n->body(), 'Batch B75 di Stok GD-A'));
});

test('BR-157 job terlewat: batch yang langsung di bawah 30 hari dapat SATU notifikasi, semua ambang tercatat', function () {
    $batch = nearBatch($this, 'B20', '2026-12-07');

    app(NearExpiryAlerts::class)->handle();

    expect(nearKeys($batch))->toBe(['ed30', 'ed60', 'ed90']);
    Notification::assertSentToTimes($this->owner, BatchNearExpiryNotification::class, 1);
});

test('BR-157 hanya batch available bersaldo dengan ED setelah hari ini dan dalam ambang terbesar', function () {
    nearBatch($this, 'JAUH', '2027-03-01'); // 104 hari
    nearBatch($this, 'HARI-INI', '2026-11-17'); // urusan ExpireBatches
    nearBatch($this, 'KAR', '2026-12-01', binId: LedgerWorld::quarantineBin($this->gd)->id);
    $empty = nearBatch($this, 'HABIS', '2026-12-01', qty: '2');
    StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($empty, Qty::of('2'), LedgerWorld::source('stock_transfer', 1), MovementType::TransferOut, '2026-11-17'));

    expect(StockBatch::query()->where('batch_no', 'KAR')->value('status'))->toBe(StockBatchStatus::Quarantine)
        ->and(app(NearExpiryAlerts::class)->handle())->toBe(['checked' => 0, 'sent' => 0]);
    Notification::assertNothingSent();
});

test('BR-157 penerima = kepala gudang lokasi stok batch; lokasi tanpa kepala gudang → Owner', function () {
    $other = LedgerWorld::warehouse('GD-B');
    FakeOrgStructure::bind()->employee(1)->at($this->gd->location_id);
    $head = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');

    nearBatch($this, 'A1', '2026-12-10');
    nearBatch($this, 'B1', '2026-12-10', location: $other);

    app(NearExpiryAlerts::class)->handle();

    Notification::assertSentToTimes($head, BatchNearExpiryNotification::class, 1);
    Notification::assertSentTo($head, BatchNearExpiryNotification::class, fn (BatchNearExpiryNotification $n) => str_contains($n->body(), 'Batch A1'));
    Notification::assertSentToTimes($this->owner, BatchNearExpiryNotification::class, 1);
    Notification::assertSentTo($this->owner, BatchNearExpiryNotification::class, fn (BatchNearExpiryNotification $n) => str_contains($n->body(), 'Batch B1'));
});

test('15 §9 ambang dari setting: [45] memakai kunci ed45', function () {
    setSetting('inventory.near_expiry_days', [45]);
    $batch = nearBatch($this, 'B40', '2026-12-27');
    nearBatch($this, 'B50', '2027-01-06');

    expect(app(NearExpiryAlerts::class)->handle())->toBe(['checked' => 1, 'sent' => 1])
        ->and(nearKeys($batch))->toBe(['ed45']);
});

test('15 §9 inventory.near_expiry_days: int_list [90,60,30], 1–3 nilai, tiap 1–365, menurun', function () {
    $d = SettingRegistry::get('inventory.near_expiry_days');
    expect([$d->type, $d->default, $d->min, $d->max, $d->isPublic])->toBe([SettingType::IntList, [90, 60, 30], 1, 3, false])
        ->and(SettingRegistry::get('inventory.min_sell_days_before_expiry')->default)->toBe(0)
        ->and(SettingRegistry::get('notification.on_batch_near_expiry')->default)->toBe([])
        ->and(SettingRegistry::get('notification.on_batch_expired')->default)->toBe([]);

    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $save = fn (string $value) => app(UpdateSettingGroup::class)->handle('inventory', ['inventory__near_expiry_days' => $value, 'inventory__negative_stock_allowed' => false], [], [], $admin->id);

    foreach (['30, 60', '90, 90', '0', '400, 30', '120, 90, 60, 30', ''] as $bad) {
        expect(fn () => $save($bad))->toThrow(ValidationException::class);
    }

    $save('120, 45');
    app()->forgetScopedInstances();
    expect(setting('inventory.near_expiry_days'))->toBe([120, 45]);
});

test('15 §7.4 widget Stok mendekati ED: jumlah kumulatif per ambang per lokasi; nilai hanya untuk stock.view_cost', function () {
    // Struktur organisasi dipasang sebelum request pertama (DataScope dibuat sekali per aplikasi uji).
    FakeOrgStructure::bind()->employee(1)->at($this->gd->location_id);
    nearBatch($this, 'D20', '2026-12-07', qty: '10'); // ≤30
    nearBatch($this, 'D50', '2027-01-06', qty: '4'); // ≤60
    nearBatch($this, 'D80', '2027-02-05', qty: '2'); // ≤90
    nearBatch($this, 'D200', '2027-06-05', qty: '100'); // di luar ambang

    $this->actingAs($this->owner)->get('/dasbor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('near_expiry.thresholds', [90, 60, 30])
        ->where('near_expiry.show_value', true)
        ->where('near_expiry.locations.0.name', 'Stok GD-A')
        ->where('near_expiry.locations.0.buckets', [
            ['days' => 90, 'batches' => 3, 'value' => '24000.00'],
            ['days' => 60, 'batches' => 2, 'value' => '21000.00'],
            ['days' => 30, 'batches' => 1, 'value' => '15000.00'],
        ]));

    // Kepala gudang (stock.view tanpa view_cost): jumlah saja, hanya lokasinya.
    $other = LedgerWorld::warehouse('GD-B');
    nearBatch($this, 'X1', '2026-12-07', location: $other);
    $head = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');

    $this->actingAs($head)->get('/dasbor')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('near_expiry.show_value', false)
        ->has('near_expiry.locations', 1)
        ->where('near_expiry.locations.0.name', 'Stok GD-A')
        ->where('near_expiry.locations.0.buckets.2', ['days' => 30, 'batches' => 1, 'value' => null]));

    // Tanpa stock.view: widget tidak dikirim.
    $this->useRealPermissions();
    $hr = User::factory()->withTwoFactor()->create()->assignRole('hrd');
    $this->actingAs($hr)->get('/dasbor')->assertOk()->assertInertia(fn (Assert $page) => $page->where('near_expiry', null));
});
