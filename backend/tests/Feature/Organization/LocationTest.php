<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationUsage;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\District;
use App\Domain\Region\Models\Province;
use App\Domain\Region\Models\Regency;
use App\Domain\Region\Models\Village;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\FakeOrgStructure;

uses(AssertsIdor::class);

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class]);
    $this->useRealPermissions();

    $this->office = ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->where('code', 'office')->sole();
    $this->warehouse = ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)->where('code', 'warehouse')->sole();

    Province::query()->create(['code' => '33', 'name' => 'Jawa Tengah']);
    Regency::query()->create(['code' => '33.19', 'province_code' => '33', 'name' => 'Kabupaten Kudus']);
    Regency::query()->create(['code' => '33.20', 'province_code' => '33', 'name' => 'Kabupaten Jepara']);
    District::query()->create(['code' => '33.19.04', 'regency_code' => '33.19', 'name' => 'Undaan']);
    District::query()->create(['code' => '33.20.01', 'regency_code' => '33.20', 'name' => 'Kedung']);
    Village::query()->create(['code' => '33.19.04.2001', 'district_code' => '33.19.04', 'name' => 'Undaan Kidul']);
    Village::query()->create(['code' => '33.20.01.2001', 'district_code' => '33.20.01', 'name' => 'Kedung Malang']);

    $this->admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
});

function makeLocation(array $attributes = []): Location
{
    return Location::query()->create([
        'code' => 'LOK-'.fake()->unique()->numberBetween(100, 999),
        'name' => 'Lokasi '.fake()->unique()->word(),
        'location_type_id' => test()->office->id,
        'is_active' => true,
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function validLocation(array $override = []): array
{
    return [
        'code' => 'gd-undaan',
        'name' => 'Gudang Undaan',
        'location_type_id' => test()->warehouse->id,
        'address' => 'Jl. Contoh No. 1',
        'village_code' => '33.19.04.2001',
        'latitude' => '-6.8512345',
        'longitude' => '110.8423456',
        'attendance_radius_m' => '150',
        'phone' => '0291-123456',
        'is_active' => true,
        ...$override,
    ];
}

test('FR-022 tambah lokasi: kode dirapikan, tersimpan, dan tercatat di audit', function () {
    $this->actingAs($this->admin)->post('/lokasi', validLocation())
        ->assertRedirect('/lokasi')
        ->assertSessionHasNoErrors();

    $location = Location::query()->sole();
    expect($location->code)->toBe('GD-UNDAAN')
        ->and($location->latitude)->toBe('-6.8512345')
        ->and($location->phone)->toBe('0291123456')
        ->and($location->created_by)->toBe($this->admin->id);

    $audit = AuditLog::query()->where('event', 'location.created')->sole();
    expect($audit->auditable_id)->toBe($location->id);
});

test('BR-041 koordinat harus di Indonesia, berpasangan, dan radius 20–5000', function (array $override, string $field) {
    $this->actingAs($this->admin)->post('/lokasi', validLocation($override))->assertSessionHasErrors($field);
    expect(Location::query()->count())->toBe(0);
})->with([
    'latitude di luar Indonesia' => [['latitude' => '10.5'], 'latitude'],
    'longitude di luar Indonesia' => [['longitude' => '150'], 'longitude'],
    'hanya latitude' => [['longitude' => ''], 'longitude'],
    'hanya longitude' => [['latitude' => ''], 'latitude'],
    'koordinat 0,0' => [['latitude' => '0', 'longitude' => '0'], 'latitude'],
    'radius 19' => [['attendance_radius_m' => '19'], 'attendance_radius_m'],
    'radius 5001' => [['attendance_radius_m' => '5001'], 'attendance_radius_m'],
    'kode tidak sah' => [['code' => 'gudang undaan!'], 'code'],
    'desa tidak ada' => [['village_code' => '33.19.04.9999'], 'village_code'],
]);

test('BR-041 tanpa koordinat & tanpa radius boleh: radius memakai setting bawaan', function () {
    setSetting('attendance.default_radius_m', 120);

    $this->actingAs($this->admin)
        ->post('/lokasi', validLocation(['latitude' => '', 'longitude' => '', 'attendance_radius_m' => '']))
        ->assertSessionHasNoErrors();

    $location = Location::query()->sole();
    expect($location->hasCoordinates())->toBeFalse()
        ->and($location->effectiveRadius())->toBe(120);
});

test('FR-022 kode unik, termasuk lokasi yang sudah dihapus', function () {
    makeLocation(['code' => 'GD-UNDAAN'])->delete();

    $this->actingAs($this->admin)->post('/lokasi', validLocation())->assertSessionHasErrors('code');
});

test('FR-022 tipe nonaktif tidak bisa dipilih, tetapi tipe terpasang boleh dipertahankan', function () {
    $location = makeLocation(['location_type_id' => $this->warehouse->id]);
    $this->warehouse->update(['is_active' => false]);

    $this->actingAs($this->admin)->post('/lokasi', validLocation())->assertSessionHasErrors('location_type_id');

    $this->actingAs($this->admin)
        ->put("/lokasi/{$location->id}", validLocation(['code' => $location->code, 'name' => 'Nama Baru']))
        ->assertSessionHasNoErrors();
    expect($location->fresh()->name)->toBe('Nama Baru');
});

test('FR-022 ubah lokasi tercatat di audit dengan nilai lama & baru', function () {
    $location = makeLocation(['name' => 'Gudang Lama']);

    $this->actingAs($this->admin)
        ->put("/lokasi/{$location->id}", validLocation(['code' => $location->code, 'name' => 'Gudang Baru']))
        ->assertRedirect('/lokasi');

    $audit = AuditLog::query()->where('event', 'location.updated')->sole();
    expect($audit->old_values['name'])->toBe('Gudang Lama')
        ->and($audit->new_values['name'])->toBe('Gudang Baru');
});

test('BR-041 lokasi yang belum dipakai bisa dihapus (soft delete + audit)', function () {
    $location = makeLocation();

    $this->actingAs($this->admin)->delete("/lokasi/{$location->id}")->assertRedirect('/lokasi');

    expect(Location::query()->count())->toBe(0)
        ->and(Location::withTrashed()->find($location->id)?->trashed())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'location.deleted')->exists())->toBeTrue();
});

test('BR-041 lokasi yang sudah dipakai penugasan tidak bisa dihapus, diarahkan untuk dinonaktifkan', function () {
    $location = makeLocation(['name' => 'Gudang Undaan']);
    // DDL biasa memutus transaksi RefreshDatabase; TEMPORARY TABLE tidak (pola T-010).
    DB::statement('CREATE TEMPORARY TABLE tmp_assignments (id INT PRIMARY KEY, location_id BIGINT UNSIGNED)');
    DB::table('tmp_assignments')->insert(['id' => 1, 'location_id' => $location->id]);
    app()->instance(LocationUsage::class, new LocationUsage([['tmp_assignments', 'location_id']]));

    $this->actingAs($this->admin)->from('/lokasi')->delete("/lokasi/{$location->id}")
        ->assertSessionHasErrors(['location' => 'Lokasi "Gudang Undaan" sudah dipakai penugasan atau absensi sehingga tidak bisa dihapus. Nonaktifkan saja.']);

    expect($location->fresh())->not->toBeNull();
});

test('BR-041 daftar menandai lokasi tanpa koordinat; cari, filter, dan sort', function () {
    makeLocation(['code' => 'KTR-01', 'name' => 'Kantor Pusat', 'village_code' => '33.19.04.2001', 'latitude' => '-6.8', 'longitude' => '110.8']);
    makeLocation(['code' => 'GD-02', 'name' => 'Gudang Kedung', 'location_type_id' => $this->warehouse->id, 'village_code' => '33.20.01.2001']);
    makeLocation(['code' => 'TK-03', 'name' => 'Toko Lama', 'is_active' => false]);

    $this->actingAs($this->admin)->get('/lokasi')
        ->assertInertia(fn (Assert $page) => $page->component('locations/index')
            ->has('rows', 3)
            ->where('rows.0.name', 'Gudang Kedung')
            ->where('rows.0.has_coordinates', false)
            ->where('rows.1.has_coordinates', true)
            ->where('can.manage', true));

    $this->actingAs($this->admin)->get('/lokasi?q=ktr')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'KTR-01'));
    $this->actingAs($this->admin)->get("/lokasi?filter[type][]={$this->warehouse->id}")
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'GD-02'));
    $this->actingAs($this->admin)->get('/lokasi?filter[has_coordinates]=0&filter[active]=1')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'GD-02'));
    $this->actingAs($this->admin)->get('/lokasi?filter[regency]=33.19')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.code', 'KTR-01'));
    $this->actingAs($this->admin)->get('/lokasi?sort=-code')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.code', 'TK-03'));
});

test('FR-022 ekspor lokasi mengunduh berkas', function () {
    makeLocation(['code' => 'KTR-01']);

    $response = $this->actingAs($this->admin)->get('/lokasi/ekspor');

    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('06 §5 kepala lokasi hanya melihat lokasinya (daftar & lookup)', function () {
    $mine = makeLocation(['code' => 'GD-01', 'name' => 'Gudang Saya']);
    $headed = makeLocation(['code' => 'TK-02', 'name' => 'Toko Dikepalai']);
    makeLocation(['code' => 'GD-03', 'name' => 'Gudang Lain']);

    $org = FakeOrgStructure::bind();
    $org->employee(1)->at($mine->id)->heads($headed->id);
    $kepala = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_lokasi');

    $this->actingAs($kepala)->get('/lokasi')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)
            ->where('rows.0.code', 'GD-01')
            ->where('rows.1.code', 'TK-02')
            ->where('can.manage', false));

    $this->actingAs($kepala)->getJson('/lookup/locations')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonMissing(['label' => 'Gudang Lain']);
});

test('SEC-033 lokasi di luar scope → 404 untuk ubah, simpan, dan hapus', function () {
    $mine = makeLocation(['code' => 'GD-01']);
    $other = makeLocation(['code' => 'GD-03']);

    FakeOrgStructure::bind()->employee(1)->at($mine->id);
    $kepala = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_lokasi');
    $kepala->givePermissionTo('locations.manage');

    $this->actingAs($kepala)->get("/lokasi/{$mine->id}/ubah")->assertOk();

    $this->assertOutOfScope404($kepala, 'GET', "/lokasi/{$other->id}/ubah");
    $this->assertOutOfScope404($kepala, 'PUT', "/lokasi/{$other->id}", validLocation(['code' => 'GD-03']));
    $this->assertOutOfScope404($kepala, 'DELETE', "/lokasi/{$other->id}");
    expect($other->fresh())->not->toBeNull();
});

test('06 §5 pengguna tanpa pegawai dengan scope lokasi tidak melihat lokasi apa pun', function () {
    makeLocation();
    $kepala = User::factory()->create(['employee_id' => null])->assignRole('kepala_lokasi');

    $this->actingAs($kepala)->get('/lokasi')->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

test('BR-041 lookup hanya lokasi aktif', function () {
    makeLocation(['name' => 'Aktif Satu']);
    makeLocation(['name' => 'Nonaktif Dua', 'is_active' => false]);

    $this->actingAs($this->admin)->getJson('/lookup/locations?q=satu')->assertJsonCount(1);
    $this->actingAs($this->admin)->getJson('/lookup/locations?q=dua')->assertJsonCount(0);
});

test('kepala lokasi tanpa locations.manage tidak bisa membuka form tambah', function () {
    // Juga memasang status pegawai aktif: tanpa itu akun ber-pegawai dikeluarkan (fail-closed, BR-010).
    FakeOrgStructure::bind();
    $kepala = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole('kepala_lokasi');

    $this->actingAs($kepala)->get('/lokasi/baru')->assertForbidden();
    $this->actingAs($kepala)->post('/lokasi', validLocation())->assertForbidden();
});
