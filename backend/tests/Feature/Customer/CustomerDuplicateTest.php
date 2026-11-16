<?php

use App\Domain\Access\Enums\DataScope as Scope;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customer\Duplicates\DuplicateFinder;
use App\Domain\Customer\Models\Customer;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Geo\Haversine;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Tests\Support\CustomerWorld;

/*
 * T-074 — kandidat duplikat kios (BR-166(2), SEC-105, TA-03) + endpoint JSON `admin.customers.duplicates`.
 * Semua data fiktif.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    CustomerWorld::regions();
    config(['inertia.testing.ensure_pages_exist' => false]);

    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
    // Titik kios lama, dan titik 120 m di utaranya (0,001078° lintang).
    $this->near = ['latitude' => '-6.8001565', 'longitude' => '110.9123456'];
    $this->existing = CustomerWorld::customer($this->area, 'TANI MAKMUR', ['latitude' => '-6.8012345', 'longitude' => '110.9123456']);
});

test('BR-166(2) normalisasi nama: huruf kecil, tanda baca & kata umum toko/tani/ud/cv dibuang', function () {
    expect(DuplicateFinder::normalizeName('Toko Tani Makmur'))->toBe('makmur')
        ->and(DuplicateFinder::normalizeName('TANI MAKMUR'))->toBe('makmur')
        ->and(DuplicateFinder::normalizeName('UD. Sumber-Rejeki'))->toBe('sumber rejeki')
        ->and(DuplicateFinder::normalizeName('Toko Tani'))->toBe('toko tani')
        ->and(DuplicateFinder::similarity('makmur', 'makmur'))->toBe(1.0)
        ->and(round(Haversine::meters(-6.8012345, 110.9123456, -6.8001565, 110.9123456)))->toBe(120.0);
});

test('BR-166(2) "Toko Tani Makmur" vs "TANI MAKMUR" berjarak 120 m = kandidat; simpan tanpa konfirmasi ditolak, dengan konfirmasi tercatat audit', function () {
    $payload = CustomerWorld::payload($this->area, ['store_name' => 'Toko Tani Makmur', ...$this->near]);

    $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', ['store_name' => 'Toko Tani Makmur', ...$this->near])
        ->assertOk()
        ->assertJsonPath('data.phone_conflict', null)
        ->assertJsonCount(1, 'data.candidates')
        ->assertJsonPath('data.candidates.0.code', $this->existing->code)
        ->assertJsonPath('data.candidates.0.reasons', ['name_nearby'])
        ->assertJsonPath('data.candidates.0.distance_m', 120)
        ->assertJsonPath('data.candidates.0.in_scope', true);

    $this->actingAs($this->apj)->post('/pelanggan', $payload)->assertSessionHasErrors('not_duplicate');
    $this->actingAs($this->apj)->post('/pelanggan', [...$payload, 'not_duplicate' => true, 'duplicate_reason' => 'x'])
        ->assertSessionHasErrors('duplicate_reason');
    expect(Customer::query()->count())->toBe(1);

    $this->actingAs($this->apj)->post('/pelanggan', [...$payload, 'not_duplicate' => true, 'duplicate_reason' => 'Toko berbeda, pemilik bersaudara'])
        ->assertSessionHasNoErrors();
    $created = Customer::query()->where('store_name', 'Toko Tani Makmur')->sole();
    $audit = AuditLog::query()->where('event', 'customer.duplicate_override')->where('auditable_id', $created->id)->sole();
    expect($audit->new_values['candidates'])->toBe([$this->existing->code])
        ->and($audit->new_values['reason'])->toBe('Toko berbeda, pemilik bersaudara');
});

test('BR-166(2) di luar radius & desa lain bukan kandidat; desa sama tanpa titik = kandidat; radius dari setting', function () {
    $far = ['latitude' => '-6.7912345', 'longitude' => '110.9123456']; // ±1,1 km
    $check = fn (array $extra) => $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', ['store_name' => 'Toko Tani Makmur', ...$extra])->assertOk();

    $check($far)->assertJsonCount(0, 'data.candidates');
    setSetting('customer.duplicate_radius_m', 5000);
    $check($far)->assertJsonCount(1, 'data.candidates');
    setSetting('customer.duplicate_radius_m', 100);
    $check($this->near)->assertJsonCount(0, 'data.candidates');

    $this->existing->forceFill(['village_code' => '33.19.01.2001'])->save();
    $check(['village_code' => '33.19.01.2001'])->assertJsonPath('data.candidates.0.reasons', ['name_same_village']);
    $check(['village_code' => '33.19.01.2002'])->assertJsonCount(0, 'data.candidates');

    // Ambang kemiripan dari setting: nama berbeda jauh tidak lolos 0,8 tetapi lolos 0,5.
    $check(['store_name' => 'Makmur Jaya', 'village_code' => '33.19.01.2001'])->assertJsonCount(0, 'data.candidates');
    setSetting('customer.duplicate_name_similarity', '0.5');
    $check(['store_name' => 'Makmur Jaya', 'village_code' => '33.19.01.2001'])->assertJsonCount(1, 'data.candidates');
});

test('BR-166(2) endpoint duplikat: galat format 05 §2.2, HP bentrok dilaporkan, kios yang diubah dikecualikan', function () {
    $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', ['store_name' => ''])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['store_name']]]);

    $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', ['store_name' => 'Kios Lain', 'owner_phone' => '0'.substr($this->existing->owner_phone, 2)])
        ->assertOk()
        ->assertJsonPath('data.phone_conflict.code', 'CUSTOMER_PHONE_DUPLICATE')
        ->assertJsonPath('data.candidates.0.reasons', ['phone']);

    $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', ['store_name' => 'TANI MAKMUR', ...$this->near, 'owner_phone' => $this->existing->owner_phone, 'customer_id' => $this->existing->id])
        ->assertOk()->assertJsonPath('data.phone_conflict', null)->assertJsonCount(0, 'data.candidates');
});

test('PA-52 SEC-033 duplikat lintas scope: kios di luar scope tampil tanpa id/pemilik/HP; customer_id di luar scope → 404', function () {
    $other = CustomerWorld::area('AR-PTI', ['name' => 'Pati']);
    $hidden = CustomerWorld::customer($other, 'Toko Sido Mulyo', ['latitude' => '-6.9000000', 'longitude' => '111.0000000']);

    $role = Role::query()->create(['name' => 'uji_sales_dup', 'label' => 'Uji', 'guard_name' => 'web', 'data_scope' => Scope::Self]);
    $role->givePermissionTo(['customers.view', 'customers.manage', 'dashboard.view']);
    $sales = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);

    $this->actingAs($sales)->postJson('/pelanggan/cek-duplikat', ['store_name' => 'Sido Mulyo', 'latitude' => '-6.9000000', 'longitude' => '111.0000000'])
        ->assertOk()
        ->assertJsonPath('data.candidates.0.code', $hidden->code)
        ->assertJsonPath('data.candidates.0.id', null)
        ->assertJsonPath('data.candidates.0.in_scope', false)
        ->assertJsonMissingPath('data.candidates.0.owner_name')
        ->assertJsonMissingPath('data.candidates.0.owner_phone')
        ->assertJsonMissingPath('data.candidates.0.distance_m')
        ->assertJsonMissingPath('data.candidates.0.similarity');

    $this->actingAs($sales)->postJson('/pelanggan/cek-duplikat', ['store_name' => 'X Y', 'customer_id' => $hidden->id])
        ->assertNotFound();
});

test('SEC-033 cek duplikat dibatasi per pengguna (anti enumerasi HP) → 429', function () {
    $payload = ['store_name' => 'Kios Uji Batas', 'owner_phone' => '6281200000999'];
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', $payload)->assertOk();
    }

    $this->actingAs($this->apj)->postJson('/pelanggan/cek-duplikat', $payload)->assertStatus(429);
});
