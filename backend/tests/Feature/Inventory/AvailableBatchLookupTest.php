<?php

use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeOrgStructure;
use Tests\Support\LedgerWorld;

/*
 * T-078 — lookup `available_batches` (15 §5, 05 §4.11): dasar BatchPicker. Data dummy.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, TransitStockLocationSeeder::class]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-17 03:00:00', 'UTC'));
    $this->gd = LedgerWorld::warehouse('GD-A');
    $this->other = LedgerWorld::warehouse('GD-B');
    $this->product = LedgerWorld::product('P-01');
    // Struktur organisasi sebelum request pertama (DataScope dibuat sekali per aplikasi uji).
    FakeOrgStructure::bind()->employee(1)->at($this->gd->location_id);
    $this->head = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');
    $this->rak = LedgerWorld::bin($this->gd, 'A-01');

    $receive = fn (string $no, ?string $expiry, string $qty = '10', ?int $bin = null, $location = null) => LedgerWorld::receive(
        $location ?? $this->gd, $this->product, $qty, '1000', new BatchKey($no, $expiry, $bin), '2026-11-10');
    $this->late = $receive('LATE-1', '2027-06-01');
    $this->first = $receive('FIRST-1', '2026-12-20', '12.5', $this->rak->id);
    $this->none = $receive('NOED-1', null);
    $this->expired = $receive('EXP-1', '2026-12-01');
    $this->quarantine = $receive('KAR-1', '2026-12-01', bin: LedgerWorld::quarantineBin($this->gd)->id);
    $receive('LAIN-1', '2026-12-01', location: $this->other);
    StockLedger::transaction(fn () => LedgerWorld::ledger()->setStatus($this->expired, StockBatchStatus::Expired));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function batchLookup(object $test, User $user, array $params): TestResponse
{
    return $test->actingAs($user)->getJson('/lookup/available_batches?'.http_build_query($params));
}

test('FR-A22 lookup available_batches: hanya batch layak, urut FEFO, ED + qty tersedia + rak + fefo_rank', function () {
    $response = batchLookup($this, $this->head, ['product_id' => $this->product->id, 'stock_location_id' => $this->gd->id])->assertOk();

    expect(array_column($response->json(), 'label'))->toBe(['FIRST-1', 'LATE-1', 'NOED-1'])
        ->and($response->json('0'))->toMatchArray([
            'value' => $this->first->id,
            'batch_no' => 'FIRST-1',
            'expiry_date' => '2026-12-20',
            'days_to_expiry' => 33,
            'expiry_state' => 'near',
            'available_base' => '12.500',
            'unit' => 'Botol',
            'bin' => 'A-01',
            'fefo_rank' => 1,
            'hint' => 'ED 20 Des 2026 · tersedia 12,5 Botol',
        ])
        ->and($response->json('1.expiry_state'))->toBe('ok')
        ->and($response->json('2.expiry_state'))->toBe('none')
        ->and($response->json('2.fefo_rank'))->toBe(3);
});

test('BR-156 fefo_rank dihitung dari urutan penuh, bukan hasil pencarian', function () {
    batchLookup($this, $this->head, ['product_id' => $this->product->id, 'stock_location_id' => $this->gd->id, 'q' => 'LATE'])
        ->assertOk()->assertJsonCount(1)->assertJsonPath('0.fefo_rank', 2);
});

test('BR-102 min_sell_days_before_expiry ikut menyaring pilihan lookup', function () {
    setSetting('inventory.min_sell_days_before_expiry', 40);

    $labels = array_column(batchLookup($this, $this->head, ['product_id' => $this->product->id, 'stock_location_id' => $this->gd->id])->json(), 'label');
    expect($labels)->toBe(['LATE-1', 'NOED-1']);
});

test('SEC-033 lookup available_batches: parameter wajib (422), tanpa stock.view (403), lokasi di luar scope kosong', function () {
    batchLookup($this, $this->head, ['product_id' => $this->product->id])->assertUnprocessable();
    batchLookup($this, $this->head, ['stock_location_id' => $this->gd->id, 'product_id' => 'x'])->assertUnprocessable();
    batchLookup($this, $this->head, ['product_id' => $this->product->id, 'stock_location_id' => $this->other->id])->assertOk()->assertJsonCount(0);

    $hr = User::factory()->withTwoFactor()->create()->assignRole('hrd');
    batchLookup($this, $hr, ['product_id' => $this->product->id, 'stock_location_id' => $this->gd->id])->assertForbidden();
});
