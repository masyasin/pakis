<?php

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomerWorld;

/*
 * T-074 — HP kios (BR-166(2)(3), SEC-105): tidak boleh sama dengan kios lain yang masih dipakai,
 * pegawai aktif, atau akun pengguna aktif (dibandingkan setelah normalisasi). Semua data fiktif.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Jakarta'));
    $this->seed([PermissionSeeder::class, RoleSeeder::class, ReferenceSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
    CustomerWorld::regions();
    config(['inertia.testing.ensure_pages_exist' => false]);

    $this->apj = User::factory()->withTwoFactor()->create()->assignRole('admin_penjualan');
    $this->area = CustomerWorld::area('AR-KDS', ['name' => 'Kudus']);
});

test('BR-166(2) CUSTOMER_PHONE_DUPLICATE: HP sama dengan kios lain yang masih dipakai ditolak, ketik 08… pun', function () {
    CustomerWorld::customer($this->area, 'Toko Sumber Rejeki', ['owner_phone' => '6281234567001']);

    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, [
        'store_name' => 'Kios Berkah', 'owner_phone' => '0812-3456-7001',
    ]))->assertSessionHasErrors(['owner_phone' => 'Nomor HP ini sudah dipakai kios lain. Satu nomor hanya untuk satu kios.']);

    expect(Customer::query()->count())->toBe(1);
});

test('BR-166(2) HP kios nonaktif tidak memblokir, tetapi menjadi kandidat duplikat yang wajib dikonfirmasi', function () {
    $old = CustomerWorld::customer($this->area, 'Toko Lama', ['owner_phone' => '6281234567001']);
    $old->forceFill(['status' => CustomerStatus::Inactive])->save();

    $payload = CustomerWorld::payload($this->area, ['store_name' => 'Kios Baru Sekali', 'owner_phone' => '081234567001']);
    $this->actingAs($this->apj)->post('/pelanggan', $payload)->assertSessionHasErrors('not_duplicate');

    $this->actingAs($this->apj)->post('/pelanggan', [...$payload, 'not_duplicate' => true, 'duplicate_reason' => 'Pemilik sama, toko lama sudah tutup'])
        ->assertSessionHasNoErrors();
    expect(Customer::query()->where('store_name', 'Kios Baru Sekali')->exists())->toBeTrue();
});

test('BR-166(3) SEC-105 PHONE_BELONGS_TO_EMPLOYEE: HP pegawai aktif (diketik 08…) ditolak; pegawai nonaktif tidak', function () {
    $employee = Employee::factory()->create(['phone' => '6281299990001']);

    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['owner_phone' => '0812 9999 0001']))
        ->assertSessionHasErrors(['owner_phone' => 'Nomor HP ini terdaftar sebagai nomor pegawai atau akun pengguna. Pakai nomor pemilik kios.']);
    expect(Customer::query()->count())->toBe(0);

    // Data pegawai lama yang tersimpan `08…` (sebelum normalisasi) tetap tertangkap.
    $employee->forceFill(['phone' => '081299990001'])->save();
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['owner_phone' => '+62 812 9999 0001']))
        ->assertSessionHasErrors('owner_phone');

    $employee->forceFill(['status' => EmployeeStatus::Inactive, 'exit_date' => '2026-11-01'])->save();
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['owner_phone' => '0812 9999 0001']))
        ->assertSessionHasNoErrors();
});

test('BR-166(3) SEC-105 PHONE_BELONGS_TO_EMPLOYEE: HP akun pengguna aktif (hanya di akun) ditolak; akun nonaktif tidak', function () {
    $account = User::factory()->create(['phone' => '6285711112222']);

    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['owner_phone' => '085711112222']))
        ->assertSessionHasErrors('owner_phone');

    $account->forceFill(['is_active' => false])->save();
    $this->actingAs($this->apj)->post('/pelanggan', CustomerWorld::payload($this->area, ['owner_phone' => '085711112222']))
        ->assertSessionHasNoErrors();
});

test('BR-166(3) ubah HP prospek ke HP pegawai ditolak; ubah nama saja tidak memeriksa ulang HP', function () {
    $customer = CustomerWorld::customer($this->area, 'Toko Sumber Rejeki', ['owner_phone' => '6281234567001']);
    Employee::factory()->create(['phone' => '6281299990001']);
    $edit = fn (array $o) => $this->actingAs($this->apj)->put("/pelanggan/{$customer->id}", [
        ...CustomerWorld::payload($this->area, ['store_name' => 'Toko Sumber Rejeki', 'owner_phone' => '6281234567001']), ...$o,
    ]);

    $edit(['owner_phone' => '081299990001'])->assertSessionHasErrors('owner_phone');
    expect($customer->fresh()->owner_phone)->toBe('6281234567001');

    // Data lama yang (mis. lewat impor) sama dengan HP pegawai tidak mengunci perubahan nama.
    DB::table('employees')->update(['phone' => '6281234567001']);
    $edit(['store_name' => 'Toko Sumber Rejeki Jaya'])->assertSessionHasNoErrors();
});
