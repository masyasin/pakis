<?php

namespace Database\Seeders;

use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Actions\SaveSalesArea;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Data demo T-073: area penjualan Kudus dan kios FIKTIF (nama toko/pemilik karangan, HP rentang
 * 0812-9000-01xx). Dipanggil DemoSeeder setelah pegawai & lokasi stok ada; bisa juga dijalankan
 * sendiri (`db:seed --class=CustomerDemoSeeder`). Lewat Action agar kode kios, penugasan awal, dan
 * audit sama dengan input layar. Titik koordinat sengaja kosong: koordinat presisi tidak boleh ada di
 * kode (CLAUDE.md §6); kios demo cukup memakai desa.
 */
class CustomerDemoSeeder extends Seeder
{
    /** kode => [nama, induk, supervisor, gudang asal, kode kecamatan] */
    private const AREAS = [
        'KDS' => ['Kudus', null, 'Agus Prasetyo', 'GD-UNDAAN', []],
        'KDS-UTR' => ['Kudus Utara', 'KDS', 'Agus Prasetyo', 'GD-UNDAAN', ['33.19.07', '33.19.08', '33.19.09', '33.19.06']],
        'KDS-SLT' => ['Kudus Selatan', 'KDS', 'Agus Prasetyo', 'GD-UNDAAN', ['33.19.01', '33.19.02', '33.19.03', '33.19.04', '33.19.05']],
    ];

    /** nama toko => [pemilik, HP, jenis, area, sales, kecamatan, urutan desa, tempo hari] */
    private const CUSTOMERS = [
        'Kios Subur Demo' => ['Slamet Riyadi', '0812-9000-0101', 'kiosk', 'KDS-UTR', 'Bayu Saputra', '33.19.09', 0, 14],
        'Toko Panen Raya Demo' => ['Siti Aminah', '0812-9000-0102', 'retail', 'KDS-UTR', 'Bayu Saputra', '33.19.08', 1, 7],
        'Kios Lestari Demo' => ['Joko Susilo', '0812-9000-0103', 'kiosk', 'KDS-UTR', 'Bayu Saputra', '33.19.06', 2, 14],
        'Kelompok Tani Ngudi Rejeki Demo' => ['Wahyudi', '0812-9000-0104', 'farmer_group', 'KDS-UTR', 'Rina Kurniasih', '33.19.07', 0, 30],
        'Toko Sri Rejeki Demo' => ['Endang Lestari', '0812-9000-0105', 'retail', 'KDS-UTR', 'Rina Kurniasih', '33.19.09', 3, 0],
        'Kios Barokah Demo' => ['Ahmad Fauzi', '0812-9000-0106', 'kiosk', 'KDS-SLT', 'Eko Wibowo', '33.19.04', 0, 14],
        'Toko Sumber Hijau Demo' => ['Dewi Rahayu', '0812-9000-0107', 'retail', 'KDS-SLT', 'Eko Wibowo', '33.19.05', 1, 7],
        'Kios Mekar Jaya Demo' => ['Bambang Sutrisno', '0812-9000-0108', 'kiosk', 'KDS-SLT', 'Eko Wibowo', '33.19.03', 2, 14],
        'Kelompok Tani Margo Mulyo Demo' => ['Suparman', '0812-9000-0109', 'farmer_group', 'KDS-SLT', 'Eko Wibowo', '33.19.01', 0, 30],
        'Kios Tunas Baru Demo' => ['Nur Hidayah', '0812-9000-0110', 'kiosk', 'KDS-SLT', null, '33.19.02', 1, 14],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('CustomerDemoSeeder ditolak di produksi: data demo hanya untuk local/staging (04 §4.2).');
        }

        $employees = Employee::query()->pluck('id', 'full_name');
        $stock = StockLocation::query()->pluck('id', 'code');

        // Hanya kecamatan yang ada di tabel wilayah (DB tanpa impor wilayah tetap bisa diisi demo).
        $knownDistricts = DB::table('districts')->where('regency_code', '33.19')->pluck('code')->all();
        foreach (self::AREAS as $code => [$name, $parent, $supervisor, $home, $districts]) {
            if (SalesArea::withTrashed()->where('code', $code)->exists()) {
                continue;
            }
            app(SaveSalesArea::class)->handle(null, [
                'code' => $code, 'name' => $name,
                'parent_id' => $parent === null ? null : SalesArea::query()->where('code', $parent)->value('id'),
                'supervisor_employee_id' => $employees[$supervisor] ?? null,
                'home_stock_location_id' => $stock[$home] ?? null,
                'district_codes' => array_values(array_intersect($districts, $knownDistricts)), 'is_active' => true,
            ], null);
        }
        $areas = SalesArea::withTrashed()->pluck('id', 'code');

        $types = ReferenceItem::query()->where('group_code', ReferenceGroup::CustomerType->value)->pluck('id', 'code');

        $seq = 0;
        foreach (self::CUSTOMERS as $store => [$owner, $phone, $type, $area, $sales, $district, $villageIndex, $term]) {
            $seq++;
            // Idempoten per nama toko: kios yang sudah ada (mungkin diubah dari layar) tidak ditimpa.
            if (Customer::withTrashed()->where('store_name', $store)->exists()) {
                continue;
            }
            $village = DB::table('villages')->where('district_code', $district)->orderBy('code')
                ->offset($villageIndex)->value('code');

            app(CreateCustomer::class)->handle([
                // Dipakai hanya bila `customer.code_mode` = manual; mode auto memakai Penomoran.
                'code' => sprintf('DM-K%03d', $seq),
                'store_name' => $store,
                'owner_name' => $owner,
                'owner_phone' => PhoneNormalizer::normalize($phone),
                'customer_type_id' => $types[$type] ?? $types->first(),
                'address' => 'Alamat demo',
                'village_code' => $village,
                'home_stock_location_id' => $stock['GD-UNDAAN'] ?? null,
                'term_days' => $term,
                'sales_area_id' => $areas[$area],
                'assigned_sales_employee_id' => $sales === null ? null : ($employees[$sales] ?? null),
            ], null);
        }
    }
}
