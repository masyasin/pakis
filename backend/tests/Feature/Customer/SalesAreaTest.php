<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Models\SalesArea;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CustomerWorld;
use Tests\Support\FakeOrgStructure;
use Tests\Support\StockWorld;

/*
 * T-073 — area penjualan bertingkat (FR-A12, 15 §4.6/§5/§7.3). Semua data fiktif.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    CustomerWorld::regions();
    // Halaman .tsx dibuat di T-073 bagian 2 (UI); test backend memeriksa props saja.
    config(['inertia.testing.ensure_pages_exist' => false]);

    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
});

/** @return array<string, mixed> */
function salesAreaPayload(array $override = []): array
{
    return [
        'code' => 'ar-kudus',
        'name' => 'Area Kudus',
        'parent_id' => null,
        'supervisor_employee_id' => null,
        'home_stock_location_id' => null,
        'district_codes' => ['33.19.01', '33.19.07'],
        'is_active' => true,
        ...$override,
    ];
}

test('FR-A12 tambah area: kode huruf besar, kecamatan tersimpan, audit sales_area.created', function () {
    $gudang = StockWorld::stockLocation(StockWorld::location('GD-UNDAAN'), 'GD-UND');

    $this->actingAs($this->apj)->from('/area-penjualan')
        ->post('/area-penjualan', salesAreaPayload(['home_stock_location_id' => $gudang->id]))
        ->assertSessionHasNoErrors()->assertRedirect('/area-penjualan');

    $area = SalesArea::query()->sole();
    expect($area->code)->toBe('AR-KUDUS')
        ->and($area->home_stock_location_id)->toBe($gudang->id)
        ->and($area->districts()->pluck('code')->sort()->values()->all())->toBe(['33.19.01', '33.19.07']);

    $audit = AuditLog::query()->where('event', 'sales_area.created')->sole();
    expect($audit->new_values)->toMatchArray(['code' => 'AR-KUDUS', 'district_codes' => ['33.19.01', '33.19.07']]);
});

test('FR-A12 satu kecamatan boleh masuk beberapa area; kode unik termasuk terhapus', function () {
    CustomerWorld::area('AR-A', ['district_codes' => ['33.19.01']]);
    $deleted = CustomerWorld::area('AR-HAPUS');
    $deleted->delete();

    $this->actingAs($this->apj)->post('/area-penjualan', salesAreaPayload(['code' => 'AR-B', 'district_codes' => ['33.19.01']]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->apj)->post('/area-penjualan', salesAreaPayload(['code' => 'ar-hapus']))
        ->assertSessionHasErrors('code');
    $this->actingAs($this->apj)->post('/area-penjualan', salesAreaPayload(['code' => 'AR-C', 'district_codes' => ['99.99.99']]))
        ->assertSessionHasErrors('district_codes.0');
});

test('FR-A12 induk: tanpa siklus dan maksimal 3 tingkat', function () {
    $a = CustomerWorld::area('AR-A');
    $b = CustomerWorld::area('AR-B', ['parent_id' => $a->id]);
    $c = CustomerWorld::area('AR-C', ['parent_id' => $b->id]);

    // Tingkat 4 ditolak.
    $this->actingAs($this->apj)->post('/area-penjualan', salesAreaPayload(['code' => 'AR-D', 'parent_id' => $c->id]))
        ->assertSessionHasErrors(['parent_id' => 'Area penjualan maksimal 3 tingkat. Pilih induk yang lebih atas.']);
    // A di bawah C = lingkaran.
    $this->actingAs($this->apj)->put("/area-penjualan/{$a->id}", salesAreaPayload(['code' => 'AR-A', 'parent_id' => $c->id]))
        ->assertSessionHasErrors('parent_id');
    // Memindahkan B (tinggi 2) ke bawah area lain tingkat 2 = 4 tingkat → ditolak.
    $x = CustomerWorld::area('AR-X');
    $y = CustomerWorld::area('AR-Y', ['parent_id' => $x->id]);
    $this->actingAs($this->apj)->put("/area-penjualan/{$b->id}", salesAreaPayload(['code' => 'AR-B', 'parent_id' => $y->id]))
        ->assertSessionHasErrors('parent_id');

    expect($a->fresh()->parent_id)->toBeNull()->and($b->fresh()->parent_id)->toBe($a->id);
});

test('FR-A12 gudang asal hanya gudang/toko (bukan kendaraan/TRANSIT)', function () {
    $vehicle = StockWorld::vehicle('K1234AB', StockWorld::location('GD-UNDAAN'));

    $this->actingAs($this->apj)->post('/area-penjualan', salesAreaPayload(['home_stock_location_id' => $vehicle->stockLocation?->id ?? 0]))
        ->assertSessionHasErrors('home_stock_location_id');
});

test('FR-A12 ubah area mencatat audit sales_area.updated termasuk perubahan kecamatan', function () {
    $area = CustomerWorld::area('AR-A', ['district_codes' => ['33.19.01']]);

    $this->actingAs($this->apj)->put("/area-penjualan/{$area->id}", salesAreaPayload(['code' => 'AR-A', 'name' => 'Area Undaan', 'district_codes' => ['33.19.07'], 'is_active' => false]))
        ->assertSessionHasNoErrors();

    $audit = AuditLog::query()->where('event', 'sales_area.updated')->sole();
    expect($audit->old_values)->toMatchArray(['district_codes' => ['33.19.01'], 'is_active' => true])
        ->and($audit->new_values)->toMatchArray(['name' => 'Area Undaan', 'district_codes' => ['33.19.07'], 'is_active' => false]);
});

test('15 §6.4 Administrator hanya melihat area (sales_areas.view), tidak bisa menambah/mengubah', function () {
    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $area = CustomerWorld::area('AR-A');

    $this->actingAs($admin)->get('/area-penjualan')
        ->assertInertia(fn (Assert $page) => $page->component('sales-areas/index')->where('can.manage', false)->has('rows', 1));
    $this->actingAs($admin)->post('/area-penjualan', salesAreaPayload())->assertForbidden();
    $this->actingAs($admin)->put("/area-penjualan/{$area->id}", salesAreaPayload())->assertForbidden();
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('hrd'))->get('/area-penjualan')->assertForbidden();
});

test('07 §3.4 daftar sales_areas: cari kode/nama, filter induk & aktif, sort, isi baris dialog', function () {
    $sup = FakeOrgStructure::bind()->employee(1);
    $a = CustomerWorld::area('AR-KDS', ['name' => 'Kudus', 'supervisor_employee_id' => employeeRow(1), 'district_codes' => ['33.19.01']]);
    CustomerWorld::area('AR-UND', ['name' => 'Undaan', 'parent_id' => $a->id]);
    CustomerWorld::area('AR-PTI', ['name' => 'Pati', 'is_active' => false]);
    CustomerWorld::customer($a, 'Toko Satu');

    $this->actingAs($this->apj)->get('/area-penjualan')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 3)
            ->where('rows.0.code', 'AR-KDS')
            ->where('rows.0.depth', 1)
            ->where('rows.0.districts.0', ['value' => '33.19.01', 'label' => 'Undaan', 'hint' => '33.19.01'])
            ->where('rows.0.supervisor.value', 1)
            ->where('rows.0.customers_count', 1)
            ->where('rows.2.code', 'AR-UND')
            ->where('rows.2.parent.label', 'Kudus')
            ->where('rows.2.depth', 2));

    $codes = fn (string $qs) => collect($this->actingAs($this->apj)->get('/area-penjualan'.$qs)->viewData('page')['props']['rows'])->pluck('code')->all();

    expect($codes('?q=und'))->toBe(['AR-UND'])
        ->and($codes('?q=AR-P'))->toBe(['AR-PTI'])
        ->and($codes("?filter[parent_id]={$a->id}"))->toBe(['AR-UND'])
        ->and($codes('?filter[is_active]=0'))->toBe(['AR-PTI'])
        ->and($codes('?filter[supervisor_employee_id]=1'))->toBe(['AR-KDS'])
        ->and($codes('?sort=-name'))->toBe(['AR-UND', 'AR-PTI', 'AR-KDS']);
});

test('05 §4.11 lookup sales_areas: aktif, exclude menyembunyikan area & sub-area, ADM boleh', function () {
    $a = CustomerWorld::area('AR-A');
    $b = CustomerWorld::area('AR-B', ['parent_id' => $a->id]);
    CustomerWorld::area('AR-C');
    CustomerWorld::area('AR-OFF', ['is_active' => false]);

    $this->actingAs($this->apj)->getJson('/lookup/sales_areas')->assertOk()->assertJsonCount(3)
        ->assertJsonFragment(['value' => $b->id, 'label' => 'Area AR-B', 'hint' => 'AR-B · Area AR-A']);
    $this->actingAs($this->apj)->getJson("/lookup/sales_areas?exclude={$a->id}")->assertOk()->assertJsonCount(1)
        ->assertJsonFragment(['hint' => 'AR-C']);

    $admin = User::factory()->withTwoFactor()->create()->assignRole('administrator');
    $this->actingAs($admin)->getJson('/lookup/sales_areas')->assertOk();
    $this->actingAs($admin)->getJson('/lookup/sales_employees')->assertOk();
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('hrd'))->getJson('/lookup/sales_areas')->assertForbidden();
});
