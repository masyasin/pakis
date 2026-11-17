<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Money\Qty;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
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
function validVehicle(array $override = []): array
{
    return [
        'plate_no' => 'k 9021 ab',
        'name' => 'Pikap Canvas 1',
        'vehicle_type' => 'pickup',
        'is_canvas' => true,
        'home_location_id' => test()->undaan->id,
        'is_active' => true,
        ...$override,
    ];
}

function canvasStockOf(Vehicle $vehicle): ?StockLocation
{
    return StockLocation::withTrashed()->where('vehicle_id', $vehicle->id)->first();
}

test('FR-A05 plat dinormalisasi huruf besar tanpa spasi; tercatat di audit', function () {
    $this->actingAs($this->admin)->post('/kendaraan', validVehicle(['is_canvas' => false]))
        ->assertRedirect('/kendaraan')
        ->assertSessionHasNoErrors();

    $vehicle = Vehicle::query()->sole();
    expect($vehicle->plate_no)->toBe('K9021AB')
        ->and(AuditLog::query()->where('event', 'vehicle.created')->where('auditable_id', $vehicle->id)->exists())->toBeTrue();
});

test('FR-A05 plat unik setelah dinormalisasi, termasuk kendaraan yang sudah dihapus', function () {
    StockWorld::vehicle('K9021AB', canvas: false)->delete();

    $this->actingAs($this->admin)->post('/kendaraan', validVehicle(['plate_no' => 'K 9021AB', 'is_canvas' => false]))->assertSessionHasErrors('plate_no');
});

test('FR-A05 isian kendaraan divalidasi', function (array $override, string $field) {
    $this->actingAs($this->admin)->post('/kendaraan', validVehicle($override))->assertSessionHasErrors($field);
    expect(Vehicle::query()->count())->toBe(0);
})->with([
    'plat bersimbol' => [['plate_no' => 'K-9021-AB'], 'plate_no'],
    'plat terlalu panjang' => [['plate_no' => str_repeat('A', 16)], 'plate_no'],
    'jenis tidak dikenal' => [['vehicle_type' => 'tank'], 'vehicle_type'],
    'canvas tanpa lokasi asal' => [['home_location_id' => ''], 'home_location_id'],
]);

test('FR-A05 kendaraan canvas → tepat satu lokasi stok vehicle, location_id = lokasi asal', function () {
    $this->actingAs($this->admin)->post('/kendaraan', validVehicle())->assertSessionHasNoErrors();
    $vehicle = Vehicle::query()->sole();

    $stock = StockLocation::query()->where('vehicle_id', $vehicle->id)->sole();
    expect($stock->type)->toBe(StockLocationType::Vehicle)
        ->and($stock->code)->toBe('KDR-K9021AB')
        ->and($stock->name)->toBe('Pikap Canvas 1')
        ->and($stock->location_id)->toBe($this->undaan->id)
        ->and($stock->is_active)->toBeTrue()
        ->and($stock->bins()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'stock_location.created')->where('auditable_id', $stock->id)->exists())->toBeTrue();

    // Simpan ulang tanpa perubahan tidak membuat lokasi stok kedua.
    $this->actingAs($this->admin)->put("/kendaraan/{$vehicle->id}", validVehicle())->assertSessionHasNoErrors();
    expect(StockLocation::query()->where('vehicle_id', $vehicle->id)->count())->toBe(1);
});

test('FR-A05 kendaraan non-canvas tidak punya lokasi stok', function () {
    $this->actingAs($this->admin)->post('/kendaraan', validVehicle(['is_canvas' => false, 'home_location_id' => '']))->assertSessionHasNoErrors();

    expect(canvasStockOf(Vehicle::query()->sole()))->toBeNull();
});

test('FR-A05 cabut canvas → lokasi stok nonaktif; centang lagi → baris yang sama aktif kembali', function () {
    $vehicle = StockWorld::vehicle('K9021AB', $this->undaan);
    $stockId = canvasStockOf($vehicle)->id;

    $this->actingAs($this->admin)->put("/kendaraan/{$vehicle->id}", validVehicle(['is_canvas' => false]))->assertSessionHasNoErrors();
    expect(canvasStockOf($vehicle)->is_active)->toBeFalse();

    $this->actingAs($this->admin)->put("/kendaraan/{$vehicle->id}", validVehicle())->assertSessionHasNoErrors();
    $stock = canvasStockOf($vehicle);
    expect($stock->id)->toBe($stockId)
        ->and($stock->is_active)->toBeTrue()
        ->and(StockLocation::query()->where('vehicle_id', $vehicle->id)->count())->toBe(1);
});

test('FR-A05 kendaraan canvas dinonaktifkan → lokasi stoknya ikut nonaktif (saldo 0)', function () {
    $vehicle = StockWorld::vehicle('K9021AB', $this->undaan);

    $this->actingAs($this->admin)->put("/kendaraan/{$vehicle->id}", validVehicle(['is_active' => false]))->assertSessionHasNoErrors();

    expect($vehicle->fresh()->is_active)->toBeFalse()
        ->and(canvasStockOf($vehicle)->is_active)->toBeFalse();
});

test('FR-A05 kendaraan canvas bersaldo tidak bisa dinonaktifkan atau dicabut canvasnya (409)', function () {
    $vehicle = StockWorld::vehicle('K9021AB', $this->undaan);
    StockWorld::fakeBalance();

    foreach ([['is_active' => false], ['is_canvas' => false]] as $change) {
        $this->actingAs($this->admin)->from("/kendaraan/{$vehicle->id}/ubah")
            ->put("/kendaraan/{$vehicle->id}", validVehicle($change))
            ->assertSessionHasErrors('form');
    }

    // Satu transaksi: kendaraan juga tidak berubah.
    expect($vehicle->fresh()->is_active)->toBeTrue()
        ->and($vehicle->fresh()->is_canvas)->toBeTrue()
        ->and(canvasStockOf($vehicle)->is_active)->toBeTrue();
});

test('FR-A05 kendaraan canvas bersaldo nyata (buku stok) ditolak saat dinonaktifkan; saldo habis → boleh', function () {
    $vehicle = StockWorld::vehicle('K9021AB', $this->undaan);
    $batch = LedgerWorld::receive(canvasStockOf($vehicle), LedgerWorld::product('P-CNV'), '2');

    $this->actingAs($this->admin)->from("/kendaraan/{$vehicle->id}/ubah")
        ->put("/kendaraan/{$vehicle->id}", validVehicle(['is_active' => false]))
        ->assertSessionHasErrors('form');
    expect($vehicle->fresh()->is_active)->toBeTrue()->and(canvasStockOf($vehicle)->is_active)->toBeTrue();

    StockLedger::transaction(fn () => LedgerWorld::ledger()->issue($batch, Qty::of(2), LedgerWorld::source('canvas_settlement', 1), MovementType::Sale, LedgerWorld::DATE));
    $this->actingAs($this->admin)->put("/kendaraan/{$vehicle->id}", validVehicle(['is_active' => false]))->assertSessionHasNoErrors();
    expect(canvasStockOf($vehicle)->is_active)->toBeFalse();
});

test('FR-A05 ubah plat, nama, dan lokasi asal ikut ke lokasi stok canvas', function () {
    $vehicle = StockWorld::vehicle('K9021AB', $this->undaan);
    $pati = StockWorld::location('GD-PATI');

    $this->actingAs($this->admin)
        ->put("/kendaraan/{$vehicle->id}", validVehicle(['plate_no' => 'K 1 X', 'name' => 'Truk Pati', 'home_location_id' => $pati->id]))
        ->assertSessionHasNoErrors();

    $stock = canvasStockOf($vehicle);
    expect($stock->code)->toBe('KDR-K1X')
        ->and($stock->name)->toBe('Truk Pati')
        ->and($stock->location_id)->toBe($pati->id);
});

test('FR-A05 kode lokasi stok canvas yang bentrok dengan lokasi stok lain ditolak', function () {
    StockLocation::query()->create(['code' => 'KDR-K9021AB', 'name' => 'Lama', 'type' => 'warehouse', 'location_id' => $this->undaan->id]);

    $this->actingAs($this->admin)->post('/kendaraan', validVehicle())->assertSessionHasErrors('plate_no');
});

test('FR-A05 daftar kendaraan: cari plat berspasi, filter canvas & jenis, sort', function () {
    StockWorld::vehicle('K9021AB', $this->undaan);
    StockWorld::vehicle('H1234CD', canvas: false, attributes: ['vehicle_type' => 'truck', 'name' => 'Truk Kantor']);

    $this->actingAs($this->admin)->get('/kendaraan')
        ->assertInertia(fn (Assert $page) => $page->component('vehicles/index')
            ->has('rows', 2)
            ->where('rows.0.plate_no', 'H1234CD')
            ->where('rows.1.stock_location_code', 'KDR-K9021AB')
            ->where('can.manage', true));

    $this->actingAs($this->admin)->get('/kendaraan?q='.urlencode('k 9021'))
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.plate_no', 'K9021AB'));
    $this->actingAs($this->admin)->get('/kendaraan?filter[is_canvas]=0')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.plate_no', 'H1234CD'));
    $this->actingAs($this->admin)->get('/kendaraan?filter[vehicle_type][]=pickup')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.vehicle_type_label', 'Pikap'));
    $this->actingAs($this->admin)->get("/kendaraan?filter[home_location_id]={$this->undaan->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.plate_no', 'K9021AB'));
    $this->actingAs($this->admin)->get('/kendaraan?sort=-plate_no')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.plate_no', 'K9021AB'));
});

test('SEC-033 kepala gudang hanya melihat kendaraan lokasinya; kendaraan lain → 404', function () {
    $mine = StockWorld::vehicle('K1111AA', $this->undaan);
    $other = StockWorld::vehicle('K2222BB', StockWorld::location('GD-PATI'));
    $homeless = StockWorld::vehicle('K3333CC', canvas: false);

    FakeOrgStructure::bind()->employee(1)->at($this->undaan->id);
    $head = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_gudang');
    $head->givePermissionTo('vehicles.manage');

    $this->actingAs($head)->get('/kendaraan')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.plate_no', 'K1111AA'));
    $this->actingAs($head)->get("/kendaraan/{$mine->id}/ubah")->assertOk();

    foreach ([$other, $homeless] as $vehicle) {
        $this->assertOutOfScope404($head, 'GET', "/kendaraan/{$vehicle->id}/ubah");
        $this->assertOutOfScope404($head, 'PUT', "/kendaraan/{$vehicle->id}", validVehicle(['plate_no' => $vehicle->plate_no]));
    }

    // Kendaraan baru tidak bisa ditautkan ke lokasi di luar scope.
    $this->actingAs($head)->post('/kendaraan', validVehicle(['plate_no' => 'K4444DD', 'home_location_id' => $other->home_location_id]))
        ->assertSessionHasErrors('home_location_id');
});

test('PA-43 supervisor (scope tim) melihat semua kendaraan tanpa izin mengubah', function () {
    StockWorld::vehicle('K1111AA', $this->undaan);
    StockWorld::vehicle('K3333CC', canvas: false);

    FakeOrgStructure::bind()->employee(1);
    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('supervisor');

    $this->actingAs($supervisor)->get('/kendaraan')->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('can.manage', false));
    $this->actingAs($supervisor)->get('/kendaraan/baru')->assertForbidden();
});
