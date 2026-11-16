<?php

namespace Tests\Support;

use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Actions\SaveSalesArea;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Support\Facades\DB;

/**
 * Data uji area penjualan & kios (T-073). Butuh ReferenceSeeder (grup `customer_type`). Pembuatan
 * lewat Action agar invarian (penugasan pertama + cache) sama dengan jalur layar. Semua fiktif.
 */
final class CustomerWorld
{
    /** Wilayah uji minimal: Jawa Tengah › Kudus › Undaan (2 desa) + Jekulo (1 desa). */
    public static function regions(): void
    {
        if (DB::table('provinces')->where('code', '33')->exists()) {
            return;
        }

        DB::table('provinces')->insert(['code' => '33', 'name' => 'JAWA TENGAH']);
        DB::table('regencies')->insert(['code' => '33.19', 'province_code' => '33', 'name' => 'KAB. KUDUS']);
        DB::table('districts')->insert([
            ['code' => '33.19.01', 'regency_code' => '33.19', 'name' => 'Undaan'],
            ['code' => '33.19.07', 'regency_code' => '33.19', 'name' => 'Jekulo'],
        ]);
        DB::table('villages')->insert([
            ['code' => '33.19.01.2001', 'district_code' => '33.19.01', 'name' => 'Undaan Lor'],
            ['code' => '33.19.01.2002', 'district_code' => '33.19.01', 'name' => 'Wonosoco'],
            ['code' => '33.19.07.2001', 'district_code' => '33.19.07', 'name' => 'Jekulo'],
        ]);
    }

    public static function type(string $code = 'kiosk'): int
    {
        return (int) ReferenceItem::query()->forGroup(ReferenceGroup::CustomerType)->where('code', $code)->value('id');
    }

    /** @param  array<string, mixed>  $attributes */
    public static function area(string $code, array $attributes = []): SalesArea
    {
        return app(SaveSalesArea::class)->handle(null, [
            'code' => $code,
            'name' => 'Area '.$code,
            'parent_id' => null,
            'supervisor_employee_id' => null,
            'home_stock_location_id' => null,
            'district_codes' => [],
            'is_active' => true,
            ...$attributes,
        ], null);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function customer(SalesArea $area, string $store, array $attributes = []): Customer
    {
        static $phone = 81200000100;

        $code = $attributes['code'] ?? null;
        if (setting('customer.code_mode') === 'auto') {
            unset($attributes['code']);
        }

        $customer = app(CreateCustomer::class)->handle([
            'store_name' => $store,
            'owner_name' => 'Pemilik '.$store,
            'owner_phone' => '62'.($phone++),
            'customer_type_id' => self::type(),
            'address' => null,
            'village_code' => null,
            'latitude' => null,
            'longitude' => null,
            'home_stock_location_id' => null,
            'term_days' => 30,
            'sales_area_id' => $area->id,
            'assigned_sales_employee_id' => null,
            ...$attributes,
        ], null);

        // Kode tetap untuk test urutan/pencarian (mode kode `auto` mengabaikan kode ketikan).
        if (is_string($code)) {
            $customer->forceFill(['code' => $code])->save();
        }

        return $customer;
    }

    /** @return array<string, mixed> isian form kios yang sah */
    public static function payload(SalesArea $area, array $override = []): array
    {
        return [
            'store_name' => 'Toko Tani Makmur',
            'owner_name' => 'Pak Darmo',
            'owner_phone' => '0812 3456 7001',
            'customer_type_id' => self::type(),
            'address' => 'Jl. Raya Undaan No. 5',
            'village_code' => null,
            'latitude' => null,
            'longitude' => null,
            'home_stock_location_id' => null,
            'term_days' => 30,
            'sales_area_id' => $area->id,
            'assigned_sales_employee_id' => null,
            ...$override,
        ];
    }
}
