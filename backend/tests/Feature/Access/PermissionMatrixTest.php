<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Access\Support\RoleCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;

/*
 * ADR-011, BR-030, FR-017: permission & role bawaan identik dengan 06 §2–§4. Dokumen, katalog
 * kode, fixture matriks, dan hasil seeder dibandingkan dua arah. 06 §4 hanya memuat izin Fase 0;
 * sel izin Paket A diuji PaketAMatrixTest terhadap 15 §6.4 (T-067).
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
    // Kolom role dibaca dari baris judul tabel, bukan ditulis ulang di sini (T-067: 10 role).
    $roles = [];
    $matrix = [];
    foreach (explode("\n", $section) as $line) {
        if (str_starts_with($line, '| Permission |')) {
            $roles = array_slice(array_map('trim', explode('|', trim($line, '| '))), 1);
            $matrix = array_fill_keys($roles, []);

            continue;
        }
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

test('ADR-011 katalog permission identik dengan 06 §3 (110 permission, urutan & nama sama)', function () {
    expect(docPermissions())->toHaveCount(110)
        ->and(PermissionCatalog::names())->toBe(docPermissions());
});

test('BR-030 fixture permission_matrix identik dengan matriks 06 §4 (10 role, hanya izin Fase 0)', function () {
    $fixture = require base_path('tests/Fixtures/permission_matrix.php');

    expect($fixture)->toBe(docMatrix())
        ->and(array_keys($fixture))->toBe(array_values(array_diff(array_column(RoleCatalog::roles(), 'name'), [RoleCatalog::SUPER_ADMIN])))
        ->and(array_intersect(array_merge(...array_values($fixture)), PermissionCatalog::paketANames()))->toBe([]);
});

test('ADR-011 PermissionSeeder: 110 permission di DB, nama identik 06 §3, idempoten & membuang yang asing', function () {
    Permission::findOrCreate('lama.tidak_dipakai', 'web');

    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->count())->toBe(110)
        ->and(Permission::query()->orderBy('id')->pluck('name')->sort()->values()->all())
        ->toBe(collect(docPermissions())->sort()->values()->all());
});

test('FR-017 RoleSeeder: 11 role bawaan + matriks izin Fase 0 identik fixture 06 §4', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $fixture = require base_path('tests/Fixtures/permission_matrix.php');
    $paketA = PermissionCatalog::paketANames();

    foreach ($fixture as $roleName => $permissions) {
        $role = Role::findByName($roleName, 'web');
        // Sel Paket A diuji PaketAMatrixTest (15 §6.4); di sini hanya izin Fase 0.
        expect($role->permissions->pluck('name')->reject(fn (string $p) => in_array($p, $paketA, true))->sort()->values()->all())
            ->toBe(collect($permissions)->sort()->values()->all(), "Matriks role {$roleName}");
    }

    expect(Role::query()->count())->toBe(11)
        ->and(Role::findByName('super_admin', 'web')->permissions)->toHaveCount(110)
        ->and(Role::findByName('kepala_lokasi', 'web')->data_scope)->toBe(DataScope::Location)
        ->and(Role::findByName('supervisor', 'web')->data_scope)->toBe(DataScope::Team)
        ->and(Role::findByName('pegawai', 'web')->is_system)->toBeTrue()
        ->and(Role::findByName('administrator', 'web')->is_system)->toBeFalse()
        ->and(Role::findByName('hrd', 'web')->mfa_required)->toBeTrue()
        // PA-12: supervisor wajib MFA sejak T-067; PA-33: kepala_lokasi tetap tidak.
        ->and(Role::findByName('supervisor', 'web')->mfa_required)->toBeTrue()
        ->and(Role::findByName('kepala_lokasi', 'web')->mfa_required)->toBeFalse();
});

test('06 §2 + 15 §6.1 role bawaan di RoleCatalog identik dengan tabel dokumen (nama, scope, MFA, sistem)', function () {
    $doc = authorizationDoc();
    $start = strpos($doc, '## 2. Role Bawaan');
    $section = substr($doc, $start, strpos($doc, '## 3. Daftar Permission') - $start);
    $documented = [];
    foreach (explode("\n", $section) as $line) {
        if (preg_match('/^\| `([a-z_]+)` \| ([^|]+) \| `([a-z]+)` \| (✅|❌) \| (✅|❌) \|/u', $line, $m)) {
            $documented[$m[1]] = ['label' => trim($m[2]), 'data_scope' => $m[3], 'mfa_required' => $m[4] === '✅', 'is_system' => $m[5] === '✅'];
        }
    }

    $catalog = [];
    foreach (RoleCatalog::roles() as $role) {
        $catalog[$role['name']] = ['label' => $role['label'], 'data_scope' => $role['data_scope']->value, 'mfa_required' => $role['mfa_required'], 'is_system' => $role['is_system']];
    }

    expect($documented)->toHaveCount(11)->and($catalog)->toBe($documented);
});

test('RoleSeeder diulang tidak menimpa matriks yang sudah diubah admin (kecuali owner/super_admin yang terkunci)', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    Role::findByName('hrd', 'web')->revokePermissionTo('holidays.manage');
    Role::findByName('owner', 'web')->revokePermissionTo('audit.export');

    $this->seed(RoleSeeder::class);

    expect(Role::findByName('hrd', 'web')->hasPermissionTo('holidays.manage'))->toBeFalse()
        ->and(Role::findByName('owner', 'web')->hasPermissionTo('audit.export'))->toBeTrue();
});
