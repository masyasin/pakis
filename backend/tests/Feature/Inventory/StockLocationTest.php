<?php

use App\Domain\Access\DataScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Support\StockBalanceProbe;
use App\Models\User;
use App\Support\Money\Qty;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\FakeOrgStructure;
use Tests\Support\LedgerWorld;
use Tests\Support\StockWorld;

uses(AssertsIdor::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class]);
    $this->useRealPermissions();

    $this->admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $this->undaan = StockWorld::location('GD-UNDAAN');
});

/** @return array<string, mixed> */
function validStockLocation(array $override = []): array
{
    return [
        'code' => 'gd-utama',
        'name' => 'Gudang Utama Undaan',
        'type' => 'warehouse',
        'location_id' => test()->undaan->id,
        'is_sellable' => true,
        'is_active' => true,
        ...$override,
    ];
}

test('FR-A03 TRANSIT tersedia tepat satu (sistem, tidak bisa dijual) dan seeder idempoten', function () {
    (new TransitStockLocationSeeder)->run();
    (new TransitStockLocationSeeder)->run();

    $transit = StockWorld::transit();
    expect(StockLocation::query()->where('type', 'in_transit')->count())->toBe(1)
        ->and($transit->is_system)->toBeTrue()
        ->and($transit->is_sellable)->toBeFalse()
        ->and($transit->location_id)->toBeNull()
        ->and($transit->bins()->count())->toBe(0);
});

test('FR-A03 CHECK sl_type_chk menolak kombinasi tipe ↔ tautan yang tidak sah (insert DB langsung)', function (string $type, bool $withLocation, bool $withVehicle) {
    $vehicle = StockWorld::vehicle('K1234AB', canvas: false);

    expect(fn () => DB::table('stock_locations')->insert([
        'code' => 'X-'.$type,
        'name' => 'Uji',
        'type' => $type,
        'location_id' => $withLocation ? $this->undaan->id : null,
        'vehicle_id' => $withVehicle ? $vehicle->id : null,
    ]))->toThrow(QueryException::class);
})->with([
    'gudang tanpa lokasi' => ['warehouse', false, false],
    'toko tanpa lokasi' => ['store', false, false],
    'gudang dengan kendaraan' => ['warehouse', true, true],
    'kendaraan tanpa kendaraan' => ['vehicle', true, false],
    'transit dengan lokasi' => ['in_transit', true, false],
    'transit dengan kendaraan' => ['in_transit', false, true],
]);

test('FR-A03 CHECK sl_type_chk menerima kombinasi sah', function () {
    $vehicle = StockWorld::vehicle('K1234AB', canvas: false);

    DB::table('stock_locations')->insert([
        ['code' => 'OK-1', 'name' => 'Gudang', 'type' => 'warehouse', 'location_id' => $this->undaan->id, 'vehicle_id' => null],
        ['code' => 'OK-2', 'name' => 'Kendaraan', 'type' => 'vehicle', 'location_id' => $this->undaan->id, 'vehicle_id' => $vehicle->id],
    ]);

    expect(StockLocation::query()->whereIn('code', ['OK-1', 'OK-2'])->count())->toBe(2);
});

test('FR-A03 tambah gudang: kode dirapikan, rak karantina Q-01 otomatis, tercatat di audit', function () {
    $this->actingAs($this->admin)->post('/lokasi-stok', validStockLocation())
        ->assertRedirect('/lokasi-stok')
        ->assertSessionHasNoErrors();

    $location = StockLocation::query()->where('code', 'GD-UTAMA')->sole();
    expect($location->type)->toBe(StockLocationType::Warehouse)
        ->and($location->location_id)->toBe($this->undaan->id)
        ->and($location->created_by)->toBe($this->admin->id);

    $quarantine = $location->bins()->sole();
    expect($quarantine->code)->toBe(StockBin::QUARANTINE_CODE)
        ->and($quarantine->zone)->toBe(BinZone::Quarantine)
        ->and($quarantine->is_active)->toBeTrue();

    expect(AuditLog::query()->where('event', 'stock_location.created')->where('auditable_id', $location->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'stock_bin.created')->where('auditable_id', $quarantine->id)->exists())->toBeTrue();
});

test('FR-A03 form hanya membuat gudang/toko; kode sistem & kode ganda ditolak', function (array $override, string $field) {
    StockWorld::stockLocation($this->undaan, 'GD-LAMA')->delete();

    $this->actingAs($this->admin)->post('/lokasi-stok', validStockLocation($override))->assertSessionHasErrors($field);
    expect(StockLocation::query()->where('code', 'GD-UTAMA')->exists())->toBeFalse();
})->with([
    'tipe kendaraan' => [['type' => 'vehicle'], 'type'],
    'tipe transit' => [['type' => 'in_transit'], 'type'],
    'tanpa lokasi' => [['location_id' => ''], 'location_id'],
    'kode awalan KDR-' => [['code' => 'KDR-K1'], 'code'],
    'kode TRANSIT' => [['code' => 'transit'], 'code'],
    'kode dipakai lokasi stok terhapus' => [['code' => 'GD-LAMA'], 'code'],
    'kode tidak sah' => [['code' => 'gudang utama!'], 'code'],
]);

test('FR-A03 lokasi nonaktif tidak bisa dipilih untuk lokasi stok baru', function () {
    $this->undaan->update(['is_active' => false]);

    $this->actingAs($this->admin)->post('/lokasi-stok', validStockLocation())->assertSessionHasErrors('location_id');
});

test('ADR-107 satu lokasi Fase 0 boleh punya beberapa lokasi stok berjenis berbeda', function () {
    $this->actingAs($this->admin)->post('/lokasi-stok', validStockLocation(['code' => 'GD-A']))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/lokasi-stok', validStockLocation(['code' => 'TK-A', 'type' => 'store']))->assertSessionHasNoErrors();

    expect(StockLocation::query()->where('location_id', $this->undaan->id)->count())->toBe(2);
});

test('FR-A03 ubah lokasi stok tercatat di audit; tipe tidak bisa diubah', function () {
    $location = StockWorld::stockLocation($this->undaan, 'GD-A');

    $this->actingAs($this->admin)
        ->put("/lokasi-stok/{$location->id}", validStockLocation(['code' => 'GD-A', 'name' => 'Gudang Baru', 'type' => 'store']))
        ->assertSessionHasErrors('type');

    $this->actingAs($this->admin)
        ->put("/lokasi-stok/{$location->id}", [...validStockLocation(['code' => 'GD-A', 'name' => 'Gudang Baru', 'is_sellable' => false]), 'type' => null])
        ->assertRedirect('/lokasi-stok');

    $location->refresh();
    expect($location->name)->toBe('Gudang Baru')
        ->and($location->is_sellable)->toBeFalse()
        ->and($location->type)->toBe(StockLocationType::Warehouse);

    $audit = AuditLog::query()->where('event', 'stock_location.updated')->sole();
    expect($audit->old_values)->toBe(['name' => 'Stok GD-A', 'is_sellable' => true])
        ->and($audit->new_values)->toBe(['name' => 'Gudang Baru', 'is_sellable' => false]);
});

test('FR-A03 lokasi stok kendaraan dari form hanya mengubah "bisa dijual"', function () {
    $vehicle = StockWorld::vehicle('K9021AB', $this->undaan);
    $stock = $vehicle->stockLocation()->sole();

    $this->actingAs($this->admin)
        ->put("/lokasi-stok/{$stock->id}", ['code' => 'LAIN', 'name' => 'Lain', 'is_sellable' => false, 'is_active' => false])
        ->assertRedirect('/lokasi-stok');

    $stock->refresh();
    expect($stock->is_sellable)->toBeFalse()
        ->and($stock->code)->toBe('KDR-K9021AB')
        ->and($stock->is_active)->toBeTrue();
});

test('FR-A03 TRANSIT tidak bisa diubah atau dinonaktifkan (403) dan tidak punya route hapus', function () {
    $transit = StockWorld::transit();
    $owner = User::factory()->withTwoFactor()->create()->assignRole('owner');

    foreach ([$this->admin, $owner] as $actor) {
        $this->actingAs($actor)->get("/lokasi-stok/{$transit->id}/ubah")->assertForbidden();
        $this->actingAs($actor)->put("/lokasi-stok/{$transit->id}", ['code' => 'TRANSIT', 'name' => 'Ganti', 'is_active' => false])->assertForbidden();
    }

    // 15 §5: lokasi stok tidak punya aksi hapus sama sekali.
    expect(Route::has('admin.stock-locations.destroy'))->toBeFalse();
    $this->actingAs($this->admin)->delete("/lokasi-stok/{$transit->id}")->assertStatus(405);

    $transit->refresh();
    expect($transit->is_active)->toBeTrue()->and($transit->name)->toBe('Barang dalam perjalanan');
});

test('FR-A03 menonaktifkan lokasi stok yang masih bersaldo ditolak 409 STATE_CONFLICT', function () {
    $location = StockWorld::stockLocation($this->undaan, 'GD-A');
    StockWorld::fakeBalance();

    $this->actingAs($this->admin)->from('/lokasi-stok')
        ->put("/lokasi-stok/{$location->id}", validStockLocation(['code' => 'GD-A', 'is_active' => false]))
        ->assertRedirect('/lokasi-stok')
        ->assertSessionHasErrors('form');

    expect($location->fresh()->is_active)->toBeTrue();
});

test('FR-A03 menonaktifkan lokasi stok/rak bersaldo nyata (buku stok) ditolak; stok karantina ikut dihitung', function () {
    $location = StockWorld::stockLocation($this->undaan, 'GD-A');
    $quarantine = $location->bins()->where('code', StockBin::QUARANTINE_CODE)->sole();
    $bin = LedgerWorld::bin($location, 'A-01');
    LedgerWorld::receive($location, LedgerWorld::product('P-LOC'), '1', key: new BatchKey('B-1', null, $quarantine->id));
    $inBin = LedgerWorld::receive($location, LedgerWorld::product('P-BIN'), '1', key: new BatchKey('B-2', null, $bin->id));

    $this->actingAs($this->admin)->from('/lokasi-stok')
        ->put("/lokasi-stok/{$location->id}", validStockLocation(['code' => 'GD-A', 'is_active' => false]))
        ->assertSessionHasErrors('form');
    expect($location->fresh()->is_active)->toBeTrue();

    $probe = app(StockBalanceProbe::class);
    expect($probe->binHasStock($bin))->toBeTrue();
    StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($inBin, Qty::of(1), LedgerWorld::source('sales_invoice', 1), MovementType::Sale, LedgerWorld::DATE));
    expect($probe->binHasStock($bin))->toBeFalse()->and($probe->locationHasStock($location))->toBeTrue();
});

test('FR-A03 daftar lokasi stok: cari, filter tipe & lokasi, sort, dan TRANSIT ikut tampil', function () {
    $jepara = StockWorld::location('GD-JEPARA');
    StockWorld::stockLocation($this->undaan, 'GD-UND', attributes: ['name' => 'Gudang Undaan']);
    StockWorld::stockLocation($jepara, 'TK-JPR', 'store', ['name' => 'Toko Jepara']);

    $this->actingAs($this->admin)->get('/lokasi-stok')
        ->assertInertia(fn (Assert $page) => $page->component('stock-locations/index')
            ->has('rows', 3)
            ->where('rows.0.code', 'GD-UND')
            ->where('rows.2.code', 'TRANSIT')
            ->where('rows.2.is_system', true)
            ->where('can.manage', true));

    $this->actingAs($this->admin)->get('/lokasi-stok?q=jepara')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'TK-JPR'));
    $this->actingAs($this->admin)->get('/lokasi-stok?filter[type][]=store')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.type_label', 'Toko'));
    $this->actingAs($this->admin)->get("/lokasi-stok?filter[location_id]={$jepara->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'TK-JPR'));
    $this->actingAs($this->admin)->get('/lokasi-stok?sort=-code')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.code', 'TRANSIT'));
});

test('SEC-033 staf gudang scope lokasi hanya melihat lokasi stok lokasinya (termasuk rangkap) + TRANSIT', function () {
    $jepara = StockWorld::location('GD-JEPARA');
    $pati = StockWorld::location('GD-PATI');
    StockWorld::stockLocation($this->undaan, 'GD-UND');
    StockWorld::stockLocation($jepara, 'GD-JPR');
    StockWorld::stockLocation($pati, 'GD-PTI');

    FakeOrgStructure::bind()->employee(1)->at($this->undaan->id)->alsoAt($jepara->id);
    $staff = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('staf_gudang');

    $this->actingAs($staff)->get('/lokasi-stok')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 3)
            ->where('rows.0.code', 'GD-JPR')
            ->where('rows.1.code', 'GD-UND')
            ->where('rows.2.code', 'TRANSIT')
            ->where('can.manage', false));

    $this->actingAs($staff)->getJson('/lookup/stock_locations')
        ->assertOk()
        ->assertJsonCount(3)
        ->assertJsonMissing(['hint' => 'GD-PTI']);
});

test('SEC-033 lokasi stok di luar scope → 404 untuk ubah, simpan, tambah rak, dan label lookup', function () {
    $mine = StockWorld::stockLocation($this->undaan, 'GD-UND');
    $other = StockWorld::stockLocation(StockWorld::location('GD-PATI'), 'GD-PTI');

    FakeOrgStructure::bind()->employee(1)->at($this->undaan->id);
    $head = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');
    $head->givePermissionTo('stock_locations.manage');

    $this->actingAs($head)->get("/lokasi-stok/{$mine->id}/ubah")->assertOk();

    $this->assertOutOfScope404($head, 'GET', "/lokasi-stok/{$other->id}/ubah");
    $this->assertOutOfScope404($head, 'PUT', "/lokasi-stok/{$other->id}", validStockLocation(['code' => 'GD-PTI']));
    $this->assertOutOfScope404($head, 'POST', '/lokasi-stok/rak', ['stock_location_id' => $other->id, 'code' => 'R1', 'zone' => 'general']);
    $this->actingAs($head)->getJson("/lookup/stock_locations?ids[]={$other->id}")->assertOk()->assertJsonCount(0);

    expect($other->fresh()->name)->toBe('Stok GD-PTI')->and($other->bins()->count())->toBe(1);
});

test('SEC-033 lokasi stok baru tidak bisa ditautkan ke lokasi di luar scope pelaku', function () {
    $pati = StockWorld::location('GD-PATI');
    FakeOrgStructure::bind()->employee(1)->at($this->undaan->id);
    $head = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');
    $head->givePermissionTo('stock_locations.manage');

    $this->actingAs($head)->post('/lokasi-stok', validStockLocation(['location_id' => $pati->id]))->assertSessionHasErrors('location_id');
    $this->actingAs($head)->post('/lokasi-stok', validStockLocation())->assertSessionHasNoErrors();
});

test('PA-43 supervisor (scope tim) melihat semua lokasi stok, tanpa izin mengubah', function () {
    StockWorld::stockLocation($this->undaan, 'GD-UND');
    StockWorld::stockLocation(StockWorld::location('GD-PATI'), 'GD-PTI');

    FakeOrgStructure::bind()->employee(1);
    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('supervisor');

    $this->actingAs($supervisor)->get('/lokasi-stok')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 3)->where('can.manage', false));
    $this->actingAs($supervisor)->get('/lokasi-stok/baru')->assertForbidden();
});

test('PA-43 kepala lokasi melihat lokasi stok kendaraan canvas yang lokasi asalnya di lokasinya', function () {
    StockWorld::vehicle('K1111AA', $this->undaan);
    StockWorld::vehicle('K2222BB', StockWorld::location('GD-PATI'));

    FakeOrgStructure::bind()->employee(1)->heads($this->undaan->id);
    $kepala = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_lokasi');

    $this->actingAs($kepala)->get('/lokasi-stok')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)
            ->where('rows.0.code', 'KDR-K1111AA')
            ->where('rows.0.vehicle_plate', 'K1111AA')
            ->where('rows.1.code', 'TRANSIT'));
});

test('15 §6.2 DataScope::forStock: scope self kosong; tanpa pegawai di scope lokasi hanya TRANSIT', function () {
    StockWorld::stockLocation($this->undaan, 'GD-UND');
    $scope = app(DataScope::class);

    $selfOnly = User::factory()->create()->assignRole('pegawai');
    $noEmployee = User::factory()->create(['employee_id' => null])->assignRole('staf_gudang');

    expect($scope->forStock(StockLocation::query(), $selfOnly, 'stock_locations.id')->count())->toBe(0)
        ->and($scope->forStock(StockLocation::query(), $noEmployee, 'stock_locations.id')->pluck('code')->all())->toBe(['TRANSIT'])
        ->and($scope->forStock(StockLocation::query(), $this->admin, 'stock_locations.id')->count())->toBe(2)
        ->and(fn () => $scope->forStock(StockLocation::query(), $this->admin, 'id; drop'))->toThrow(InvalidArgumentException::class);
});

test('BR-041 lokasi Fase 0 yang dipakai lokasi stok tidak bisa dihapus', function () {
    StockWorld::stockLocation($this->undaan, 'GD-UND');

    $this->actingAs($this->admin)->delete("/lokasi/{$this->undaan->id}")->assertSessionHasErrors('location');
    expect($this->undaan->fresh())->not->toBeNull();
});
