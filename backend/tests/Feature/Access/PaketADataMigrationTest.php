<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Access\Support\RoleCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * T-067, PA-11, PA-12: migrasi data `add_paket_a_permissions_to_builtin_roles` = jalur upgrade
 * instalasi yang sudah berjalan. Hanya menambah sel Paket A (15 §6.4), membuat 4 role baru,
 * menyalakan MFA supervisor; idempoten; tidak mencabut/menimpa ubahan pengguna.
 */

function paketADataMigration(): Migration
{
    return require database_path('migrations/2026_11_16_200000_add_paket_a_permissions_to_builtin_roles.php');
}

/** @return array<string, list<string>> */
function rolePermissionSnapshot(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::query()->with('permissions')->orderBy('name')->get()
        ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->all();
}

/**
 * Keadaan instalasi Fase 0 (sebelum T-067): seed lengkap lalu buang izin & role Paket A dan
 * kembalikan supervisor tanpa MFA.
 */
function simulateFase0Install(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class]);
    Role::query()->whereIn('name', RoleCatalog::PAKET_A_ROLES)->delete();
    Permission::query()->whereIn('name', PermissionCatalog::paketANames())->delete();
    Role::findByName('supervisor', 'web')->forceFill(['mfa_required' => false])->save();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

test('PA-11 instalasi baru (tabel roles kosong): migrasi data tidak melakukan apa pun', function () {
    paketADataMigration()->up();

    expect(Role::query()->count())->toBe(0)
        ->and(Permission::query()->count())->toBe(0);
});

test('PA-11 PA-12 migrasi data: hanya menambah sel Paket A, membuat 4 role baru, supervisor MFA, idempoten', function () {
    simulateFase0Install();

    // Ubahan pengguna sebelum upgrade: cabut izin, tambah izin di luar matriks, role kustom,
    // dan role Fase 0 yang dihapus.
    Role::findByName('hrd', 'web')->revokePermissionTo('holidays.manage');
    Role::findByName('supervisor', 'web')->givePermissionTo('devices.view');
    Role::findByName('administrator', 'web')->forceFill(['label' => 'Admin TI', 'data_scope' => DataScope::Location])->save();
    $custom = Role::create(['name' => 'kasir_khusus', 'guard_name' => 'web', 'label' => 'Kasir Khusus', 'data_scope' => 'self']);
    $custom->givePermissionTo('dashboard.view');
    Role::findByName('kepala_lokasi', 'web')->delete();
    expect(Permission::query()->count())->toBe(54);

    $before = rolePermissionSnapshot();

    $migration = paketADataMigration();
    $migration->up();
    $first = rolePermissionSnapshot();
    $migration->up();
    $second = rolePermissionSnapshot();

    expect($second)->toBe($first)
        ->and(Permission::query()->count())->toBe(110);

    // Role yang sudah ada: tepat = sebelum ∪ sel Paket A role itu (tidak ada yang dicabut).
    foreach ($before as $role => $permissions) {
        expect($first[$role])->toBe(
            collect([...$permissions, ...RoleCatalog::paketAPermissionsFor($role)])->unique()->sort()->values()->all(),
            "Role {$role}",
        );
    }

    expect($first['hrd'])->not->toContain('holidays.manage')
        ->and($first['supervisor'])->toContain('devices.view', 'customers.verify', 'credit_limits.set')
        ->and($first['kasir_khusus'])->toBe(['dashboard.view'])
        ->and($first['owner'])->toHaveCount(110)
        ->and($first['super_admin'])->toHaveCount(110)
        ->and($first)->not->toHaveKey('kepala_lokasi')
        ->and(Role::findByName('administrator', 'web')->label)->toBe('Admin TI')
        ->and(Role::findByName('administrator', 'web')->data_scope)->toBe(DataScope::Location)
        ->and(Role::findByName('supervisor', 'web')->mfa_required)->toBeTrue();

    // 4 role baru dibuat lengkap dari RoleCatalog (izin, scope, MFA).
    $definitions = collect(RoleCatalog::roles())->keyBy('name');
    foreach (RoleCatalog::PAKET_A_ROLES as $name) {
        $role = Role::findByName($name, 'web');
        expect($first[$name])->toBe(collect(RoleCatalog::permissionsFor($name))->sort()->values()->all(), "Role {$name}")
            ->and($first[$name])->toContain('dashboard.view')
            ->and($role->data_scope)->toBe($definitions[$name]['data_scope'])
            ->and($role->mfa_required)->toBe($definitions[$name]['mfa_required'])
            ->and($role->is_system)->toBeFalse();
    }
    expect(Role::findByName('admin_penjualan', 'web')->mfa_required)->toBeTrue()
        ->and(Role::findByName('staf_gudang', 'web')->mfa_required)->toBeFalse();
});

test('PA-11 migrasi data pada instalasi Fase 0 tanpa ubahan menghasilkan matriks yang sama dengan instalasi baru', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $fresh = rolePermissionSnapshot();

    simulateFase0Install();
    paketADataMigration()->up();

    expect(rolePermissionSnapshot())->toBe($fresh);
});

test('PA-11 role Paket A yang sudah dibuat pengguna lebih dulu hanya ditambah sel Paket A-nya', function () {
    simulateFase0Install();
    $own = Role::create(['name' => 'purchasing', 'guard_name' => 'web', 'label' => 'Pembelian', 'data_scope' => 'location']);
    $own->givePermissionTo('regions.view');

    paketADataMigration()->up();

    $role = Role::findByName('purchasing', 'web');
    expect($role->label)->toBe('Pembelian')
        ->and($role->data_scope)->toBe(DataScope::Location)
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(['regions.view', ...RoleCatalog::paketAPermissionsFor('purchasing')])->sort()->values()->all());
});
