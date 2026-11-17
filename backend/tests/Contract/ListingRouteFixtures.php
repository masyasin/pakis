<?php

namespace Tests\Contract;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Support\DeviceTokens;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Actions\SaveStockLocation;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Organization\Models\Location;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\Province;
use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportStatus;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Data pendukung uji HTTP ListingContractTest per route (10 §3.3).
 */
final class ListingRouteFixtures
{
    /**
     * Parameter untuk route berparameter, mis. `admin.imports.rows.index` butuh `import`.
     *
     * @return array<string, mixed>
     */
    public static function parameters(string $routeName): array
    {
        return match ($routeName) {
            // Batch tervalidasi tanpa berkas: cukup untuk menguji bentuk daftar baris.
            'admin.imports.rows.index' => ['batch' => (function () {
                $batch = (new ImportBatch)->forceFill([
                    'type' => ImportBatch::TYPE_EMPLOYEES,
                    'original_name' => 'kontrak.xlsx',
                    'stored_path' => 'imports/kontrak.xlsx',
                    'file_hash' => str_repeat('0', 64),
                    'status' => ImportStatus::Validated,
                    'mapping' => ['header_row' => 1, 'headers' => ['Nama'], 'columns' => ['0' => 'full_name']],
                ]);
                $batch->save();

                return $batch->id;
            })()],
            // Versi draf tanpa item: cukup untuk menguji bentuk daftar item (T-075).
            'admin.price-lists.items.index' => ['version' => (function () {
                // Dipanggil berulang dalam satu test: pakai ulang bila sudah ada.
                $list = PriceList::query()->where('code', 'KONTRAK')->first();
                if ($list === null) {
                    $list = (new PriceList)->forceFill(['code' => 'KONTRAK', 'name' => 'Daftar kontrak', 'kind' => 'tier', 'is_active' => true]);
                    $list->save();
                }
                $version = PriceListVersion::query()->firstWhere('price_list_id', $list->id)
                    ?? (new PriceListVersion)->forceFill(['price_list_id' => $list->id, 'version_no' => 1, 'effective_from' => now(), 'status' => 'draft']);
                $version->save();

                return $version->id;
            })()],
            // Opname draf tanpa baris (T-083): cukup untuk menguji bentuk lembar hitung.
            'admin.stock-opnames.lines.index' => ['opname' => (function () {
                $existing = StockOpname::query()->where('number', 'OPN-KONTRAK')->value('id');
                if ($existing !== null) {
                    return $existing;
                }
                $location = Location::query()->firstOrCreate(['code' => 'KONTRAK-OPN'], [
                    'name' => 'Gudang Opname Kontrak',
                    'location_type_id' => ReferenceItem::query()->firstOrCreate(['group_code' => 'location_type', 'code' => 'warehouse'], ['name' => 'Gudang'])->id,
                ]);
                $stock = app(SaveStockLocation::class)->handle(null, [
                    'code' => 'KONTRAK-OPN', 'name' => 'Gudang Opname Kontrak', 'type' => 'warehouse',
                    'location_id' => $location->id, 'is_sellable' => true, 'is_active' => true,
                ], null);
                $opname = (new StockOpname)->forceFill([
                    'number' => 'OPN-KONTRAK', 'stock_location_id' => $stock->id, 'scope' => 'full', 'blind_count' => false, 'status' => 'review',
                ]);
                $opname->save();

                return $opname->id;
            })()],
            default => [],
        };
    }

    /**
     * Header API untuk route `api.v1.*`: token Sanctum + perangkat aktif + versi app, karena grup API
     * mewajibkan perangkat terikat (BR-040). Persetujuan = HRD (scope all), lainnya = pegawai.
     *
     * @return array<string, string>
     */
    public static function apiHeaders(string $routeName): array
    {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();

        $role = str_starts_with($routeName, 'api.v1.approvals.') ? 'hrd' : RoleCatalog::PEGAWAI;
        $employee = Employee::factory()->create();
        $user = User::factory()->withTwoFactor()->create(['employee_id' => $employee->id])->assignRole($role);
        $uid = hash('sha256', 'kontrak-'.$user->id);
        $device = Device::query()->create(['user_id' => $user->id, 'device_uid' => $uid, 'status' => DeviceStatus::Active, 'model' => 'Uji Kontrak']);

        return [
            'Accept' => 'application/json',
            'X-App-Version' => '1.0.0',
            'X-Device-Id' => $uid,
            'Authorization' => 'Bearer '.app(DeviceTokens::class)->issue($user, $device)->plainTextToken,
        ];
    }

    /**
     * Pengguna yang membuka daftar web: super_admin. Route API memakai apiHeaders().
     */
    public static function actor(string $routeName): Authenticatable
    {
        if (str_starts_with($routeName, 'admin.')) {
            // Web: super_admin (lolos semua permission) — kontrak listing menguji bentuk daftar,
            // bukan otorisasi (itu harness T-019).
            (new PermissionSeeder)->run();
            (new RoleSeeder)->run();

            // MFA aktif: super_admin wajib MFA (BR-015), tanpa itu semua halaman dialihkan ke aktivasi.
            return User::factory()->withTwoFactor()->create()->assignRole(RoleCatalog::SUPER_ADMIN);
        }

        return match ($routeName) {
            default => User::factory()->create(),
        };
    }

    /**
     * Nilai contoh yang PASTI valid untuk filter yang tidak bisa ditebak dari spec (filter relasi).
     *
     * @return array<string, mixed> filterKey => nilai
     */
    public static function filterSamples(string $routeName): array
    {
        return match ($routeName) {
            // Tabel wilayah kosong di test: buat satu provinsi agar contoh filter pasti ada.
            'admin.regions.index' => (function () {
                Province::query()->firstOrCreate(['code' => '33'], ['name' => 'Jawa Tengah']);

                return ['province' => '33'];
            })(),
            // Filter wajib lokasi stok (T-068): satu gudang dengan rak Q-01 agar contoh pasti valid.
            'admin.stock-bins.index' => (function () {
                $location = Location::query()->create([
                    'code' => 'KONTRAK-GD',
                    'name' => 'Gudang Kontrak',
                    'location_type_id' => ReferenceItem::query()->firstOrCreate(['group_code' => 'location_type', 'code' => 'warehouse'], ['name' => 'Gudang'])->id,
                ]);

                return ['stock_location_id' => app(SaveStockLocation::class)->handle(null, [
                    'code' => 'KONTRAK-GD', 'name' => 'Gudang Kontrak', 'type' => 'warehouse',
                    'location_id' => $location->id, 'is_sellable' => true, 'is_active' => true,
                ], null)->id];
            })(),
            default => [],
        };
    }
}
