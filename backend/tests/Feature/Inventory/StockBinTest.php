<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Models\StockBin;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\FakeOrgStructure;
use Tests\Support\StockWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class]);
    $this->useRealPermissions();

    $this->admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $this->undaan = StockWorld::location('GD-UNDAAN');
    $this->warehouse = StockWorld::stockLocation($this->undaan, 'GD-UND');
});

test('FR-A04 tambah rak: kode dirapikan, zona tersimpan, tercatat di audit', function () {
    $this->actingAs($this->admin)->from('/lokasi-stok/rak')
        ->post('/lokasi-stok/rak', ['stock_location_id' => $this->warehouse->id, 'code' => 'r2-04', 'name' => 'Rak pestisida', 'zone' => 'pesticide'])
        ->assertRedirect('/lokasi-stok/rak')
        ->assertSessionHasNoErrors();

    $bin = StockBin::query()->where('code', 'R2-04')->sole();
    expect($bin->zone)->toBe(BinZone::Pesticide)
        ->and($bin->stock_location_id)->toBe($this->warehouse->id)
        ->and(AuditLog::query()->where('event', 'stock_bin.created')->where('auditable_id', $bin->id)->exists())->toBeTrue();
});

test('FR-A04 kode rak unik per lokasi stok, boleh sama di lokasi stok lain', function () {
    $other = StockWorld::stockLocation($this->undaan, 'TK-UND', 'store');
    $post = fn (int $locationId) => $this->actingAs($this->admin)
        ->post('/lokasi-stok/rak', ['stock_location_id' => $locationId, 'code' => 'R1', 'zone' => 'general']);

    $post($this->warehouse->id)->assertSessionHasNoErrors();
    $post($this->warehouse->id)->assertSessionHasErrors('code');
    $post($other->id)->assertSessionHasNoErrors();
});

test('FR-A04 rak untuk lokasi stok kendaraan atau TRANSIT ditolak (15 §4.8)', function () {
    $canvas = StockWorld::vehicle('K9021AB', $this->undaan)->stockLocation()->sole();

    foreach ([$canvas, StockWorld::transit()] as $location) {
        $this->actingAs($this->admin)
            ->post('/lokasi-stok/rak', ['stock_location_id' => $location->id, 'code' => 'R1', 'zone' => 'general'])
            ->assertSessionHasErrors('stock_location_id');
        expect($location->bins()->count())->toBe(0);
    }
});

test('FR-A04 rak karantina Q-01: kode, zona, dan status terkunci; nama boleh diubah', function () {
    $q01 = $this->warehouse->bins()->sole();
    $put = fn (array $data) => $this->actingAs($this->admin)
        ->put("/lokasi-stok/rak/{$q01->id}", ['code' => 'Q-01', 'name' => 'Karantina', 'zone' => 'quarantine', 'is_active' => true, ...$data]);

    $put(['zone' => 'general'])->assertSessionHasErrors('zone');
    $put(['is_active' => false])->assertSessionHasErrors('is_active');
    $put(['code' => 'Q-99'])->assertSessionHasErrors('code');
    $put(['name' => 'Karantina ED'])->assertSessionHasNoErrors();

    $q01->refresh();
    expect($q01->name)->toBe('Karantina ED')->and($q01->zone)->toBe(BinZone::Quarantine)->and($q01->is_active)->toBeTrue();
});

test('FR-A04 rak karantina tambahan boleh dibuat dan diubah bebas', function () {
    $this->actingAs($this->admin)
        ->post('/lokasi-stok/rak', ['stock_location_id' => $this->warehouse->id, 'code' => 'Q-02', 'zone' => 'quarantine'])
        ->assertSessionHasNoErrors();
    $bin = StockBin::query()->where('code', 'Q-02')->sole();

    $this->actingAs($this->admin)
        ->put("/lokasi-stok/rak/{$bin->id}", ['code' => 'Q-02', 'zone' => 'general', 'is_active' => false])
        ->assertSessionHasNoErrors();
    expect($bin->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('event', 'stock_bin.updated')->where('auditable_id', $bin->id)->exists())->toBeTrue();
});

test('FR-A04 rak tidak bisa dipindah ke lokasi stok lain', function () {
    $bin = $this->warehouse->bins()->sole();
    $other = StockWorld::stockLocation($this->undaan, 'TK-UND', 'store');

    $this->actingAs($this->admin)
        ->put("/lokasi-stok/rak/{$bin->id}", ['stock_location_id' => $other->id, 'code' => 'Q-01', 'zone' => 'quarantine', 'is_active' => true])
        ->assertSessionHasErrors('stock_location_id');
});

test('FR-A04 menonaktifkan rak yang masih bersaldo ditolak 409 STATE_CONFLICT', function () {
    $this->actingAs($this->admin)->post('/lokasi-stok/rak', ['stock_location_id' => $this->warehouse->id, 'code' => 'R1', 'zone' => 'general']);
    $bin = StockBin::query()->where('code', 'R1')->sole();
    StockWorld::fakeBalance();

    $this->actingAs($this->admin)->from('/lokasi-stok/rak')
        ->put("/lokasi-stok/rak/{$bin->id}", ['code' => 'R1', 'zone' => 'general', 'is_active' => false])
        ->assertSessionHasErrors('form');
    expect($bin->fresh()->is_active)->toBeTrue();
});

test('FR-A04 daftar rak: filter lokasi stok wajib (default gudang/toko pertama), cari, zona, sort', function () {
    $store = StockWorld::stockLocation($this->undaan, 'TK-UND', 'store');
    foreach (['R1' => 'general', 'R2' => 'pesticide'] as $code => $zone) {
        $this->actingAs($this->admin)->post('/lokasi-stok/rak', ['stock_location_id' => $this->warehouse->id, 'code' => $code, 'zone' => $zone]);
    }

    // Tanpa filter: jatuh ke lokasi stok gudang/toko aktif pertama (GD-UND), bukan semua rak.
    $this->actingAs($this->admin)->get('/lokasi-stok/rak')
        ->assertInertia(fn (Assert $page) => $page->component('stock-bins/index')
            ->has('rows', 3)
            ->where('listing.state.filters.stock_location_id', $this->warehouse->id)
            ->where('stockLocation.code', 'GD-UND')
            ->where('canManage', true));

    $this->actingAs($this->admin)->get("/lokasi-stok/rak?filter[stock_location_id]={$store->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'Q-01')->where('rows.0.is_default_quarantine', true));
    $this->actingAs($this->admin)->get('/lokasi-stok/rak?filter[zone][]=pesticide')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'R2'));
    $this->actingAs($this->admin)->get('/lokasi-stok/rak?q=r1')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'R1'));
    $this->actingAs($this->admin)->get('/lokasi-stok/rak?sort=-code')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.code', 'R2'));
});

test('SEC-033 rak lokasi stok di luar scope: tidak tampil, filter jatuh ke default, ubah → 404, lookup kosong', function () {
    $other = StockWorld::stockLocation(StockWorld::location('GD-PATI'), 'GD-PTI');
    $otherBin = $other->bins()->sole();

    FakeOrgStructure::bind()->employee(1)->at($this->undaan->id);
    $head = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');
    $head->givePermissionTo('stock_locations.manage');

    $this->actingAs($head)->get("/lokasi-stok/rak?filter[stock_location_id]={$other->id}")
        ->assertInertia(fn (Assert $page) => $page->where('listing.state.filters.stock_location_id', $this->warehouse->id)
            ->has('listing.state.invalid_filters.stock_location_id')
            ->where('rows.0.code', 'Q-01')
            ->where('stockLocation.code', 'GD-UND'));

    $this->assertOutOfScope404($head, 'PUT', "/lokasi-stok/rak/{$otherBin->id}", ['code' => 'Q-01', 'name' => 'X', 'zone' => 'quarantine', 'is_active' => true]);
    $this->actingAs($head)->getJson("/lookup/stock_bins?parent={$other->id}")->assertOk()->assertJsonCount(0);
    $this->actingAs($head)->getJson("/lookup/stock_bins?parent={$this->warehouse->id}")->assertOk()->assertJsonCount(1);

    expect($otherBin->fresh()->name)->toBe('Karantina');
});

test('staf gudang hanya melihat rak, tidak bisa menambah atau mengubah', function () {
    FakeOrgStructure::bind()->employee(1)->at($this->undaan->id);
    $staff = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('staf_gudang');
    $bin = $this->warehouse->bins()->sole();

    $this->actingAs($staff)->get('/lokasi-stok/rak')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('canManage', false));
    $this->actingAs($staff)->post('/lokasi-stok/rak', ['stock_location_id' => $this->warehouse->id, 'code' => 'R1', 'zone' => 'general'])->assertForbidden();
    $this->actingAs($staff)->put("/lokasi-stok/rak/{$bin->id}", ['code' => 'Q-01', 'zone' => 'quarantine'])->assertForbidden();
});
