<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;

/*
 * ADR-011, BR-030, FR-017: permission & role bawaan identik dengan 06 §2–§4. Dokumen, katalog
 * kode, fixture matriks, dan hasil seeder dibandingkan dua arah.
 */

function authorizationDoc(): string
{
    return (string) file_get_contents(base_path('../docs/06-AUTHORIZATION.md'));
}

/** @return list<string> */
function docPermissions(): array
{
    $doc = authorizationDoc();
    $section = substr($doc, strpos($doc, '## 3. Daftar Permission'), strpos($doc, '## 4. Matriks') - strpos($doc, '## 3. Daftar Permission'));
    $names = [];
    foreach (explode("\n", $section) as $line) {
        $cells = array_map('trim', explode('|', trim($line, '| ')));
        if (count($cells) < 2 || ! str_contains($cells[1], '`')) {
            continue;
        }
        preg_match_all('/`([a-z_]+\.[a-z_]+)`/', $cells[1], $m);
        array_push($names, ...$m[1]);
    }

    return $names;
}

/** @return array<string, list<string>> */
function docMatrix(): array
{
    $doc = authorizationDoc();
    $start = strpos($doc, '## 4. Matriks');
    $section = substr($doc, $start, strpos($doc, '## 5. Scope Data') - $start);
    $roles = ['owner', 'administrator', 'hrd', 'kepala_lokasi', 'supervisor', 'pegawai'];
    $matrix = array_fill_keys($roles, []);
    foreach (explode("\n", $section) as $line) {
        if (! str_starts_with($line, '| `')) {
            continue;
        }
        $cells = array_map('trim', explode('|', trim($line, '| ')));
        preg_match_all('/`([a-z_]+\.[a-z_]+)`/', $cells[0], $m);
        foreach ($roles as $i => $role) {
            if (in_array($cells[$i + 1] ?? '', ['✅', '🔸'], true)) {
                array_push($matrix[$role], ...$m[1]);
            }
        }
    }

    return $matrix;
}

test('ADR-011 katalog permission identik dengan 06 §3 (54 permission, urutan & nama sama)', function () {
    expect(docPermissions())->toHaveCount(54)
        ->and(PermissionCatalog::names())->toBe(docPermissions());
});

test('BR-030 fixture permission_matrix identik dengan matriks 06 §4', function () {
    $fixture = require base_path('tests/Fixtures/permission_matrix.php');

    expect($fixture)->toBe(docMatrix());
});

test('ADR-011 PermissionSeeder: 54 permission di DB, nama identik 06 §3, idempoten & membuang yang asing', function () {
    Permission::findOrCreate('lama.tidak_dipakai', 'web');

    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->count())->toBe(54)
        ->and(Permission::query()->orderBy('id')->pluck('name')->sort()->values()->all())
        ->toBe(collect(docPermissions())->sort()->values()->all());
});

test('FR-017 RoleSeeder: 7 role bawaan + matriks identik fixture 06 §4', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $fixture = require base_path('tests/Fixtures/permission_matrix.php');

    foreach ($fixture as $roleName => $permissions) {
        $role = Role::findByName($roleName, 'web');
        expect($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect($permissions)->sort()->values()->all(), "Matriks role {$roleName}");
    }

    expect(Role::query()->count())->toBe(7)
        ->and(Role::findByName('super_admin', 'web')->permissions)->toHaveCount(54)
        ->and(Role::findByName('kepala_lokasi', 'web')->data_scope)->toBe(DataScope::Location)
        ->and(Role::findByName('supervisor', 'web')->data_scope)->toBe(DataScope::Team)
        ->and(Role::findByName('pegawai', 'web')->is_system)->toBeTrue()
        ->and(Role::findByName('administrator', 'web')->is_system)->toBeFalse()
        ->and(Role::findByName('hrd', 'web')->mfa_required)->toBeTrue();
});

test('RoleSeeder diulang tidak menimpa matriks yang sudah diubah admin (kecuali owner/super_admin yang terkunci)', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    Role::findByName('hrd', 'web')->revokePermissionTo('holidays.manage');
    Role::findByName('owner', 'web')->revokePermissionTo('audit.export');

    $this->seed(RoleSeeder::class);

    expect(Role::findByName('hrd', 'web')->hasPermissionTo('holidays.manage'))->toBeFalse()
        ->and(Role::findByName('owner', 'web')->hasPermissionTo('audit.export'))->toBeTrue();
});
