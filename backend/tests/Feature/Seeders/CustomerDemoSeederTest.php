<?php

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAssignment;
use App\Domain\Customer\Models\SalesArea;
use Database\Seeders\CustomerDemoSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

test('T-073 data demo: 3 area Kudus & 10 kios fiktif prospek dengan penugasan awal; idempoten, tidak menimpa layar', function () {
    // Sebagian wilayah Kudus saja: kecamatan yang tidak ada di tabel wilayah dilewati seeder.
    DB::table('provinces')->insert(['code' => '33', 'name' => 'JAWA TENGAH']);
    DB::table('regencies')->insert(['code' => '33.19', 'province_code' => '33', 'name' => 'KAB. KUDUS']);
    DB::table('districts')->insert([
        ['code' => '33.19.04', 'regency_code' => '33.19', 'name' => 'Undaan'],
        ['code' => '33.19.05', 'regency_code' => '33.19', 'name' => 'Mejobo'],
    ]);
    DB::table('villages')->insert(['code' => '33.19.04.2001', 'district_code' => '33.19.04', 'name' => 'Desa Demo']);

    $this->seed(DemoSeeder::class);
    Customer::query()->where('store_name', 'Kios Subur Demo')->update(['owner_name' => 'Diubah dari layar']);
    $this->seed(DemoSeeder::class);

    $areas = SalesArea::query()->with('parent')->get()->keyBy('code');
    expect($areas)->toHaveCount(3)
        ->and($areas['KDS-UTR']->parent?->code)->toBe('KDS')
        ->and($areas['KDS-SLT']->districts()->pluck('code')->all())->toBe(['33.19.04', '33.19.05'])
        ->and($areas['KDS-UTR']->districts()->count())->toBe(0);

    $customers = Customer::query()->get();
    expect($customers)->toHaveCount(10)
        ->and($customers->every(fn (Customer $c) => $c->status === CustomerStatus::Prospect && $c->latitude === null))->toBeTrue()
        ->and($customers->firstWhere('store_name', 'Kios Barokah Demo')?->village_code)->toBe('33.19.04.2001')
        ->and($customers->firstWhere('store_name', 'Kios Subur Demo')?->owner_name)->toBe('Diubah dari layar')
        ->and($customers->whereNull('assigned_sales_employee_id'))->toHaveCount(1)
        ->and(CustomerAssignment::query()->count())->toBe(10);
});

test('CLAUDE.md §6 seeder demo kios ditolak di produksi', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new CustomerDemoSeeder)->run())->toThrow(RuntimeException::class);
    expect(SalesArea::query()->count())->toBe(0);

    app()->detectEnvironment(fn () => 'testing');
});
