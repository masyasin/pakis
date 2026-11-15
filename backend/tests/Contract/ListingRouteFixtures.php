<?php

namespace Tests\Contract;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Models\ImportBatch;
use App\Domain\Region\Models\Province;
use App\Models\User;
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
            default => [],
        };
    }

    /**
     * Pengguna yang membuka daftar. Web: super_admin; API: pegawai/supervisor dengan perangkat aktif.
     */
    public static function actor(string $routeName): Authenticatable
    {
        // TODO(T-031): pengguna + perangkat aktif untuk api.v1.*.
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
            default => [],
        };
    }
}
