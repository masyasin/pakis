<?php

use App\Domain\Access\Models\Role;
use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Access\Support\RoleCatalog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;

/*
 * T-067, PA-10/PA-11/PA-25: matriks izin Paket A identik dengan tabel 15 §6.4 (sumber kebenaran),
 * data-driven: setiap sel ✅/🔸/❌ dari 10 kolom role dibandingkan dengan katalog & hasil RoleSeeder.
 * 06 §4 (izin Fase 0) diuji PermissionMatrixTest.
 */

/**
 * Tabel 15 §6.4 → [kode role => [permission => punya?]] + urutan baris. Kolom singkatan dipetakan
 * lewat legenda di atas tabel ("OWN `owner` · ADM `administrator` …"), bukan ditulis ulang di test.
 *
 * @return array{rows: list<string>, matrix: array<string, array<string, bool>>}
 */
function paketADocMatrix(): array
{
    // Dataset dievaluasi sebelum aplikasi menyala: path relatif, bukan base_path().
    $doc = (string) file_get_contents(__DIR__.'/../../../../docs/15-PAKET-A-MASTER-GUDANG.md');
    $start = strpos($doc, '### 6.4 Matriks');
    $section = substr($doc, $start, strpos($doc, '### 6.5 Menu') - $start);

    preg_match_all('/([A-Z]{2,3}) `([a-z_]+)`/', $section, $legend, PREG_SET_ORDER);
    $codes = [];
    foreach ($legend as [, $abbr, $role]) {
        $codes[$abbr] = $role;
    }

    $columns = [];
    $rows = [];
    $matrix = [];
    foreach (explode("\n", $section) as $line) {
        $cells = array_map('trim', explode('|', trim($line, "| \r")));
        if (str_starts_with($line, '| Permission |')) {
            $columns = array_map(fn (string $abbr) => $codes[$abbr], array_slice($cells, 1));
            $matrix = array_fill_keys($columns, []);

            continue;
        }
        if (! str_starts_with($line, '| `') || ! preg_match('/^`([a-z_]+\.[a-z_]+)`$/', $cells[0], $m)) {
            continue;
        }
        $rows[] = $m[1];
        foreach ($columns as $i => $role) {
            $mark = $cells[$i + 1] ?? '';
            if (! in_array($mark, ['✅', '🔸', '❌'], true)) {
                throw new UnexpectedValueException("15 §6.4: sel {$m[1]} × {$role} = '{$mark}' tidak dikenal");
            }
            $matrix[$role][$m[1]] = in_array($mark, ['✅', '🔸'], true);
        }
    }

    return ['rows' => $rows, 'matrix' => $matrix];
}

dataset('paket_a_roles', fn () => array_keys(paketADocMatrix()['matrix']));

test('T-067 tabel 15 §6.4: 56 baris = izin Paket A katalog (urutan sama), 10 kolom = role non-super_admin', function () {
    $doc = paketADocMatrix();

    expect($doc['rows'])->toHaveCount(56)
        ->and($doc['rows'])->toBe(PermissionCatalog::paketANames())
        ->and(array_keys($doc['matrix']))->toEqualCanonicalizing(
            array_values(array_diff(array_column(RoleCatalog::roles(), 'name'), [RoleCatalog::SUPER_ADMIN])),
        );
});

test('T-067 15 §6.4 matriks Paket A per role = RoleCatalog = hasil RoleSeeder (56 sel)', function (string $role) {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $expected = paketADocMatrix()['matrix'][$role];
    $seeded = Role::findByName($role, 'web')->permissions->pluck('name')->all();
    $catalog = RoleCatalog::permissionsFor($role);

    expect($expected)->toHaveCount(56);
    foreach ($expected as $permission => $granted) {
        expect(in_array($permission, $catalog, true))->toBe($granted, "RoleCatalog {$role} · {$permission}")
            ->and(in_array($permission, $seeded, true))->toBe($granted, "RoleSeeder {$role} · {$permission}");
    }
    expect(RoleCatalog::paketAPermissionsFor($role))
        ->toEqualCanonicalizing(array_keys(array_filter($expected)));
})->with('paket_a_roles');

test('PA-10 owner & super_admin memegang semua 56 izin Paket A dan terkunci', function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);

    foreach (RoleCatalog::LOCKED as $role) {
        expect(Role::findByName($role, 'web')->permissions->pluck('name')->all())
            ->toContain(...PermissionCatalog::paketANames())
            ->and(Role::findByName($role, 'web')->isLocked())->toBeTrue();
    }
});

test('PA-11 izin baru di katalog default ❌ untuk semua role selain owner/super_admin', function () {
    $catalog = [...PermissionCatalog::names(), 'uji_paket_baru.view', 'uji_paket_baru.manage'];

    foreach (RoleCatalog::roles() as $definition) {
        $granted = RoleCatalog::permissionsFor($definition['name'], $catalog);
        $locked = in_array($definition['name'], RoleCatalog::LOCKED, true);

        expect(in_array('uji_paket_baru.view', $granted, true))->toBe($locked, "Role {$definition['name']}")
            ->and(in_array('uji_paket_baru.manage', $granted, true))->toBe($locked, "Role {$definition['name']}");
        if (! $locked) {
            // Daftar eksplisit: tidak ada izin yang tidak dikenal katalog (salah ketik) dan tidak ada duplikat.
            expect(array_diff($granted, PermissionCatalog::names()))->toBe([], "Role {$definition['name']}")
                ->and($granted)->toBe(array_values(array_unique($granted)));
        }
    }
});

test('PA-11 administrator & hrd tetap memegang izin Fase 0 persis seperti sebelum T-067 (allowlist)', function () {
    $fase0 = array_values(array_diff(PermissionCatalog::names(), PermissionCatalog::paketANames()));
    $except = fn (array $denied) => array_values(array_diff($fase0, $denied));

    // Rumus lama "semua kecuali" (RoleCatalog sebelum T-067) atas 54 izin Fase 0.
    $before = [
        'administrator' => $except([
            'audit.export', 'employees.create', 'employees.update', 'employees.delete', 'employees.view_sensitive',
            'employees.offboard', 'employees.import', 'employees.export', 'assignments.manage', 'shifts.manage',
            'schedules.manage', 'holidays.manage', 'attendance.view_photo', 'attendance.export',
            'attendance_corrections.approve', 'field_trips.manage', 'field_trips.approve',
        ]),
        'hrd' => $except([
            'settings.view', 'settings.update', 'sequences.view', 'sequences.update', 'users.reset_mfa',
            'users.export', 'roles.view', 'roles.manage', 'audit.view', 'audit.export',
        ]),
    ];

    foreach ($before as $role => $permissions) {
        expect(array_values(array_intersect(RoleCatalog::permissionsFor($role), $fase0)))
            ->toEqualCanonicalizing($permissions);
    }
    expect($before['administrator'])->toHaveCount(37)->and($before['hrd'])->toHaveCount(44);
});

test('PA-25 login web 4 role Paket A mendarat di /dasbor dengan 200 (izin nyata dashboard.view)', function (string $role) {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
    // MFA aktif bila role mewajibkan: yang diuji pendaratan setelah login, bukan aktivasi MFA.
    $factory = Role::findByName($role, 'web')->mfa_required ? User::factory()->withTwoFactor() : User::factory();
    $factory->create(['username' => "uji.{$role}"])->assignRole($role);

    $login = $this->post('/login', ['login' => "uji.{$role}", 'password' => 'password']);

    if (Role::findByName($role, 'web')->mfa_required) {
        // Login ber-MFA lewat challenge; sesudahnya pengguna mendarat di dasbor.
        $login->assertRedirect('/two-factor-challenge');
        $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertRedirect('/dasbor');
    } else {
        $login->assertRedirect('/dasbor');
    }

    $this->assertAuthenticated();
    $this->get('/dasbor')->assertOk();
})->with(RoleCatalog::PAKET_A_ROLES);
