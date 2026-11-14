<?php

namespace Database\Seeders;

use App\Domain\Access\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission resmi dari PermissionCatalog (06 §3, ADR-011, BR-030). Idempoten: permission yang
 * tidak lagi ada di katalog dihapus, agar daftar di DB selalu identik dengan dokumen.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $names = PermissionCatalog::names();

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Permission::query()->where('guard_name', 'web')->whereNotIn('name', $names)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
