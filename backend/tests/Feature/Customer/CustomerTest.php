<?php

use App\Domain\Access\DataScope;
use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Listings\CustomerListing;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CustomerWorld;
use Tests\Support\FakeOrgStructure;
use Tests\Support\StockWorld;

/*
 * T-073 — kios inti (FR-A13 data inti), scope Pelanggan (15 §6.2, SEC-033), ekspor (SEC-108).
 * "Hari ini" = Senin 16 Nov 2026 WIB. Semua data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    CustomerWorld::regions();

    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
});

/** Role uji ber-scope `self` (Sales Paket B belum ada): hanya melihat kios. */
function selfSalesRole(): Role
{
    $role = Role::query()->create(['name' => 'uji_sales', 'label' => 'Uji Sales', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    // dashboard.view: gerbang web (BR-017) butuh satu izin menu; menu Pelanggan dibuat bagian 2 UI.
    $role->givePermissionTo(['customers.view', 'dashboard.view']);

    return $role;
}

function customerCodes(User $user, string $qs = ''): array
{
    return collect(test()->actingAs($user)->get('/pelanggan'.$qs)->assertOk()->viewData('page')['props']['rows'])->pluck('code')->all();
}

test('FR-A13 tambah kios: kode KIO-00001, status prospek, HP 62…, penugasan pertama hari ini, audit', function () {
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, [
        'village_code' => '33.19.01.2001', 'latitude' => '-6.8012345', 'longitude' => '110.9123456',
    ]))->assertSessionHasNoErrors();

    $customer = Customer::query()->sole();
    expect($customer->code)->toBe('KIO-00001')
        ->and($customer->status)->toBe(CustomerStatus::Prospect)
        ->and($customer->credit_limit_active)->toBeFalse()
        ->and($customer->owner_phone)->toBe('6281234567001')
        ->and($customer->latitude)->toBe('-6.8012345')
        ->and($customer->longitude)->toBe('110.9123456')
        ->and($customer->sales_area_id)->toBe($this->area->id);

    $assignment = CustomerAssignment::query()->sole();
    expect($assignment->start_date->toDateString())->toBe('2026-11-16')
        ->and($assignment->end_date)->toBeNull()
        ->and($assignment->sales_area_id)->toBe($this->area->id);

    expect(AuditLog::query()->where('event', 'customer.created')->where('auditable_id', $customer->id)->exists())->toBeTrue();
});

test('FR-A13 mode kode manual: kode wajib, format [A-Z0-9-]{3,20}, unik termasuk terhapus', function () {
    setSetting('customer.code_mode', 'manual');
    $old = CustomerWorld::customer($this->area, 'Toko Lama', ['code' => 'KIO-LAMA']);
    $old->delete();

    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area))->assertSessionHasErrors('code');
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['code' => 'k!']))->assertSessionHasErrors('code');
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['code' => 'kio-lama']))->assertSessionHasErrors('code');
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['code' => 'kio-und-01']))->assertSessionHasNoErrors();

    expect(Customer::query()->where('code', 'KIO-UND-01')->exists())->toBeTrue();
});

test('FR-A13 validasi: HP tak sah, titik tanpa pasangan, jenis bukan customer_type, gudang asal kendaraan', function () {
    $vehicle = StockWorld::vehicle('K1234AB', StockWorld::location('GD-UNDAAN'));
    $unitId = DB::table('reference_items')->where('group_code', 'unit')->value('id');

    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, [
        'owner_phone' => '021-1234',
        'latitude' => '-6.8',
        'customer_type_id' => $unitId,
        'home_stock_location_id' => $vehicle->stockLocation?->id,
    ]))->assertSessionHasErrors(['owner_phone', 'longitude', 'customer_type_id', 'home_stock_location_id']);
});

test('15 §9 form kios baru: termin bawaan & mode kode dari setting, jenis pelanggan aktif', function () {
    setSetting('customer.default_term_days', 14);

    $this->actingAs($this->apj)->get('/pelanggan/baru')
        ->assertInertia(fn (Assert $page) => $page->component('customers/form')
            ->where('customer', null)
            ->where('defaultTermDays', 14)
            ->where('codeMode', 'auto')
            ->has('customerTypes', 5)
            ->where('customerTypes.0.label', 'Kios'));
});

test('FR-A13 ubah profil: audit customer.updated; area/sales/kode tidak ikut berubah dari form', function () {
    $customer = CustomerWorld::customer($this->area, 'Toko Satu');
    $other = CustomerWorld::area('AR-LAIN');

    $this->actingAs($this->apj)->put("/pelanggan/{$customer->id}", CustomerWorld::payload($other, [
        'store_name' => 'Toko Satu Jaya', 'owner_phone' => '0812 3456 7999', 'code' => 'X-1', 'term_days' => 45,
    ]))->assertSessionHasNoErrors()->assertRedirect("/pelanggan/{$customer->id}");

    $customer->refresh();
    expect($customer->store_name)->toBe('Toko Satu Jaya')
        ->and($customer->owner_phone)->toBe('6281234567999')
        ->and($customer->term_days)->toBe(45)
        ->and($customer->sales_area_id)->toBe($this->area->id)
        ->and($customer->code)->not->toBe('X-1');
    expect(AuditLog::query()->where('event', 'customer.updated')->sole()->new_values)->toMatchArray(['store_name' => 'Toko Satu Jaya', 'term_days' => 45]);
});

test('BR-126 PA-47 HP pemilik hanya bisa diubah selama prospek & belum terverifikasi → selain itu 409', function () {
    $customer = CustomerWorld::customer($this->area, 'Toko Aktif');
    DB::table('customers')->where('id', $customer->id)->update(['status' => 'active', 'verified_at' => now(), 'owner_phone_verified_at' => now()]);
    $phone = $customer->owner_phone;

    $this->actingAs($this->apj)->from("/pelanggan/{$customer->id}/ubah")
        ->put("/pelanggan/{$customer->id}", CustomerWorld::payload($this->area, ['store_name' => 'Toko Aktif', 'owner_phone' => '081299990000']))
        ->assertSessionHasErrors('form');
    expect($customer->fresh()->owner_phone)->toBe($phone);

    // Tanpa mengubah HP, profil tetap bisa disimpan.
    $this->actingAs($this->apj)->put("/pelanggan/{$customer->id}", CustomerWorld::payload($this->area, ['store_name' => 'Toko Aktif Baru', 'owner_phone' => $phone]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->apj)->get("/pelanggan/{$customer->id}/ubah")
        ->assertInertia(fn (Assert $page) => $page->where('customer.phone_editable', false));
});

test('SEC-108 HP penuh untuk customers.manage, termasking untuk yang hanya melihat; ekspor selalu termasking', function () {
    $customer = CustomerWorld::customer($this->area, 'Toko HP', ['owner_phone' => '628123456789']);
    $viewer = User::factory()->withTwoFactor()->create()->assignRole(Role::query()->create(['name' => 'uji_lihat', 'label' => 'Uji Lihat', 'guard_name' => 'web', 'data_scope' => Scope::All])->givePermissionTo(['customers.view', 'dashboard.view']));

    $this->actingAs($this->apj)->get('/pelanggan')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.owner_phone', '628123456789')->where('phoneMasked', false));
    $this->actingAs($viewer)->get('/pelanggan')
        ->assertInertia(fn (Assert $page) => $page->where('rows.0.owner_phone', '081•••••789')->where('phoneMasked', true));
    $this->actingAs($viewer)->get("/pelanggan/{$customer->id}")
        ->assertInertia(fn (Assert $page) => $page->component('customers/show')->where('customer.owner_phone', '081•••••789'));

    $columns = (new CustomerListing)->exportColumns();
    $row = array_combine($columns->headers(), $columns->values($customer->fresh()));
    expect($row['HP pemilik'])->toBe('081•••••789')
        ->and(json_encode($row))->not->toContain('628123456789');
});

test('SEC-108 ekspor customers: tanpa customers.export → 403; admin penjualan mengunduh .xlsx', function () {
    CustomerWorld::customer($this->area, 'Toko Ekspor');
    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => employeeRow(1)])->assignRole('supervisor');
    FakeOrgStructure::bind()->employee(1);

    $this->actingAs($supervisor)->get('/pelanggan/ekspor')->assertForbidden();
    $response = $this->actingAs($this->apj)->get('/pelanggan/ekspor');
    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('.xlsx');
});

test('15 §6.2 SEC-033 supervisor (scope tim) melihat kios area yang ia supervisi + sub-area + milik sales timnya', function () {
    FakeOrgStructure::bind()->employee(1)->employee(2)->reportsTo(1)->employee(3);
    $mine = CustomerWorld::area('AR-SPV', ['supervisor_employee_id' => employeeRow(1)]);
    $sub = CustomerWorld::area('AR-SUB', ['parent_id' => $mine->id]);
    $other = CustomerWorld::area('AR-LAIN', ['supervisor_employee_id' => employeeRow(3)]);

    CustomerWorld::customer($mine, 'A Area Saya', ['code' => 'C-1']);
    CustomerWorld::customer($sub, 'B Sub Area', ['code' => 'C-2']);
    CustomerWorld::customer($other, 'C Sales Tim', ['code' => 'C-3', 'assigned_sales_employee_id' => employeeRow(2)]);
    CustomerWorld::customer($other, 'D Orang Lain', ['code' => 'C-4', 'assigned_sales_employee_id' => employeeRow(3)]);

    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => 1])->assignRole('supervisor');

    expect(customerCodes($supervisor))->toBe(['C-1', 'C-2', 'C-3']);
    $this->actingAs($supervisor)->getJson('/lookup/customers')->assertOk()->assertJsonCount(3)->assertJsonMissing(['label' => 'D Orang Lain']);
});

test('15 §6.2 scope self (role uji, Sales Paket B) hanya melihat kios yang ditugaskan kepadanya', function () {
    FakeOrgStructure::bind()->employee(5)->employee(6);
    CustomerWorld::customer($this->area, 'Milik Saya', ['code' => 'S-1', 'assigned_sales_employee_id' => employeeRow(5)]);
    CustomerWorld::customer($this->area, 'Milik Orang', ['code' => 'S-2', 'assigned_sales_employee_id' => employeeRow(6)]);
    CustomerWorld::customer($this->area, 'Tanpa Sales', ['code' => 'S-3']);

    $sales = User::factory()->create(['employee_id' => 5])->assignRole(selfSalesRole());

    expect(customerCodes($sales))->toBe(['S-1']);
    // PA-47: scope self belum bisa membuat/mengalihkan kios dari web.
    $sales->givePermissionTo('customers.manage');
    $this->actingAs($sales)->get('/pelanggan/baru')->assertForbidden();
});

test('15 §6.2 DataScope::forCustomer scope lokasi: kios dengan gudang asal (atau gudang asal area) di lokasi pengguna', function () {
    $undaan = StockWorld::location('GD-UNDAAN');
    $pati = StockWorld::location('GD-PATI');
    $slUndaan = StockWorld::stockLocation($undaan, 'GD-UND');
    $slPati = StockWorld::stockLocation($pati, 'GD-PTI');
    $areaUndaan = CustomerWorld::area('AR-UND', ['home_stock_location_id' => $slUndaan->id]);

    CustomerWorld::customer($areaUndaan, 'Ikut Area', ['code' => 'L-1']);
    CustomerWorld::customer($this->area, 'Gudang Sendiri', ['code' => 'L-2', 'home_stock_location_id' => $slUndaan->id]);
    CustomerWorld::customer($areaUndaan, 'Gudang Lain', ['code' => 'L-3', 'home_stock_location_id' => $slPati->id]);
    CustomerWorld::customer($this->area, 'Tanpa Gudang', ['code' => 'L-4']);

    FakeOrgStructure::bind()->employee(1)->at($undaan->id);
    $role = Role::query()->create(['name' => 'uji_lokasi', 'label' => 'Uji Lokasi', 'guard_name' => 'web', 'data_scope' => Scope::Location]);
    $user = User::factory()->create(['employee_id' => employeeRow(1)])->assignRole($role);
    $noEmployee = User::factory()->create(['employee_id' => null])->assignRole($role);

    $scope = app(DataScope::class);
    expect($scope->forCustomer(Customer::query(), $user, 'customers.id')->orderBy('code')->pluck('code')->all())->toBe(['L-1', 'L-2'])
        ->and($scope->forCustomer(Customer::query(), $noEmployee, 'customers.id')->count())->toBe(0)
        ->and($scope->forCustomer(Customer::query(), $this->apj, 'customers.id')->count())->toBe(4)
        ->and(fn () => $scope->forCustomer(Customer::query(), $this->apj, 'id; drop'))->toThrow(InvalidArgumentException::class);
});

test('SEC-033 IDOR kios di luar scope → 404 di show, edit, update (dan label lookup kosong)', function () {
    FakeOrgStructure::bind()->employee(1)->employee(3);
    $mine = CustomerWorld::area('AR-SPV', ['supervisor_employee_id' => employeeRow(1)]);
    $own = CustomerWorld::customer($mine, 'Kios Saya');
    $foreign = CustomerWorld::customer($this->area, 'Kios Orang', ['assigned_sales_employee_id' => employeeRow(3)]);
    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => 1])->assignRole('supervisor');

    $this->actingAs($supervisor)->get("/pelanggan/{$own->id}")->assertOk();
    $this->assertOutOfScope404($supervisor, 'GET', "/pelanggan/{$foreign->id}");
    $this->assertOutOfScope404($supervisor, 'GET', "/pelanggan/{$foreign->id}/ubah");
    $this->assertOutOfScope404($supervisor, 'PUT', "/pelanggan/{$foreign->id}", CustomerWorld::payload($this->area));
    $this->actingAs($supervisor)->getJson("/lookup/customers?ids[]={$foreign->id}")->assertOk()->assertJsonCount(0);

    expect($foreign->fresh()->store_name)->toBe('Kios Orang');
});

test('SEC-033 supervisor hanya bisa membuat kios di area yang ia supervisi dengan sales timnya', function () {
    FakeOrgStructure::bind()->employee(1)->employee(2)->reportsTo(1)->employee(3);
    $mine = CustomerWorld::area('AR-SPV', ['supervisor_employee_id' => employeeRow(1)]);
    employeeRow(2);
    employeeRow(3);
    $supervisor = User::factory()->withTwoFactor()->create(['employee_id' => 1])->assignRole('supervisor');

    $this->actingAs($supervisor)->post('/pelanggan', CustomerWorld::payload($this->area))->assertSessionHasErrors('sales_area_id');
    $this->actingAs($supervisor)->post('/pelanggan', CustomerWorld::payload($mine, ['assigned_sales_employee_id' => 3]))->assertSessionHasErrors('assigned_sales_employee_id');
    $this->actingAs($supervisor)->post('/pelanggan', CustomerWorld::payload($mine, ['assigned_sales_employee_id' => 2]))->assertSessionHasNoErrors();

    $this->actingAs($supervisor)->getJson('/lookup/sales_areas?assignable=1')->assertOk()->assertJsonCount(1)->assertJsonFragment(['hint' => 'AR-SPV']);
    $this->actingAs($supervisor)->getJson('/lookup/sales_employees')->assertOk()->assertJsonCount(2);
});

test('07 §3.4 daftar customers: cari nama/kode/HP persis, filter status/area/jenis/terverifikasi/kecamatan, sort', function () {
    $jekulo = CustomerWorld::area('AR-JKL');
    CustomerWorld::customer($this->area, 'Tani Makmur', ['code' => 'K-1', 'owner_phone' => '6281200001111', 'village_code' => '33.19.01.2001']);
    $b = CustomerWorld::customer($jekulo, 'Bumi Subur', ['code' => 'K-2', 'customer_type_id' => CustomerWorld::type('retail'), 'village_code' => '33.19.07.2001']);
    CustomerWorld::customer($this->area, 'Cahaya Tani', ['code' => 'K-3']);
    DB::table('customers')->where('id', $b->id)->update(['status' => 'active', 'verified_at' => now()]);

    expect(customerCodes($this->apj))->toBe(['K-2', 'K-3', 'K-1'])
        ->and(customerCodes($this->apj, '?q=tani'))->toBe(['K-3', 'K-1'])
        ->and(customerCodes($this->apj, '?q=K-2'))->toBe(['K-2'])
        ->and(customerCodes($this->apj, '?q=0812-0000-1111'))->toBe(['K-1'])
        ->and(customerCodes($this->apj, '?q=1111'))->toBe([])
        ->and(customerCodes($this->apj, '?filter[status][]=active'))->toBe(['K-2'])
        ->and(customerCodes($this->apj, "?filter[sales_area_id]={$jekulo->id}"))->toBe(['K-2'])
        ->and(customerCodes($this->apj, '?filter[customer_type_id][]='.CustomerWorld::type('retail')))->toBe(['K-2'])
        ->and(customerCodes($this->apj, '?filter[verified]=0'))->toBe(['K-3', 'K-1'])
        ->and(customerCodes($this->apj, '?filter[district_code]=33.19.01'))->toBe(['K-1'])
        ->and(customerCodes($this->apj, '?sort=-code'))->toBe(['K-3', 'K-2', 'K-1']);
});

test('FR-A13 detail kios: profil, wilayah, riwayat penugasan, izin aksi', function () {
    $customer = CustomerWorld::customer($this->area, 'Toko Detail', ['village_code' => '33.19.01.2002']);

    $this->actingAs($this->apj)->get("/pelanggan/{$customer->id}")
        ->assertInertia(fn (Assert $page) => $page->component('customers/show')
            ->where('customer.code', $customer->code)
            ->where('customer.status', 'prospect')
            ->where('customer.status_label', 'Prospek')
            ->where('customer.region.district', 'Undaan')
            ->where('customer.region.village', 'Wonosoco')
            ->where('customer.sales_area.name', 'Kudus')
            ->has('assignments', 1)
            ->where('assignments.0.state', 'current')
            ->where('can.update', true)
            ->where('can.reassign', true));
});
