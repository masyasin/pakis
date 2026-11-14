<?php

use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;

/*
 * Harness otorisasi (10 §4, T-019): role × permission dari `tests/Fixtures/permission_matrix.php`
 * → satu route perwakilan per permission. Pemegang (✅/🔸) lolos (2xx/3xx), bukan pemegang 403.
 * Role tanpa satu pun menu web (pegawai) dikeluarkan dari web (BR-017) → ke halaman masuk.
 *
 * MODUL BARU: pindahkan permission-nya dari PENDING ke REPRESENTATIVE begitu route-nya ada.
 */

/** permission → [method, uri]. `{sequence}` diganti ID penomoran yang ada. */
const REPRESENTATIVE = [
    'dashboard.view' => ['GET', '/dasbor'],
    'settings.view' => ['GET', '/pengaturan'],
    'settings.update' => ['PUT', '/pengaturan/monitoring'],
    'sequences.view' => ['GET', '/pengaturan/penomoran'],
    'sequences.update' => ['PUT', '/pengaturan/penomoran/{sequence}'],
    'references.view' => ['GET', '/referensi'],
    'references.manage' => ['POST', '/referensi'],
    'regions.view' => ['GET', '/wilayah'],
    'roles.view' => ['GET', '/role'],
    'roles.manage' => ['GET', '/role/baru'],
    'audit.view' => ['GET', '/audit'],
    'audit.export' => ['GET', '/audit/ekspor'],
];

/** Permission yang modulnya belum dibangun → task pembuatnya (ditambahkan ke REPRESENTATIVE di sana). */
const PENDING = [
    'users.view' => 'T-020', 'users.create' => 'T-020', 'users.update' => 'T-020', 'users.deactivate' => 'T-020',
    'users.reset_password' => 'T-020', 'users.reset_mfa' => 'T-020', 'users.export' => 'T-020',
    'locations.view' => 'T-021', 'locations.manage' => 'T-021',
    'departments.view' => 'T-022', 'departments.manage' => 'T-022',
    'positions.view' => 'T-023', 'positions.manage' => 'T-023',
    'employees.view' => 'T-024', 'employees.create' => 'T-024', 'employees.update' => 'T-024', 'employees.delete' => 'T-024',
    'employees.view_sensitive' => 'T-024', 'employees.offboard' => 'T-026', 'employees.import' => 'T-027', 'employees.export' => 'T-024',
    'assignments.view' => 'T-025', 'assignments.manage' => 'T-025',
    'devices.view' => 'T-031', 'devices.manage' => 'T-031',
    'shifts.view' => 'T-033', 'shifts.manage' => 'T-033',
    'holidays.view' => 'T-034', 'holidays.manage' => 'T-034',
    'schedules.view' => 'T-035', 'schedules.manage' => 'T-035',
    'attendance.view' => 'T-038', 'attendance.view_photo' => 'T-038', 'attendance.export' => 'T-038', 'attendance.self' => 'T-036',
    'attendance_corrections.view' => 'T-041', 'attendance_corrections.create' => 'T-040', 'attendance_corrections.approve' => 'T-041',
];

dataset('role_permission', function () {
    // Dataset dievaluasi sebelum aplikasi menyala: path relatif, bukan base_path().
    $matrix = require __DIR__.'/../../Fixtures/permission_matrix.php';
    $matrix['super_admin'] = PermissionCatalog::names();

    foreach ($matrix as $role => $granted) {
        foreach (array_keys(REPRESENTATIVE) as $permission) {
            yield "{$role} · {$permission}" => [$role, $permission, in_array($permission, $granted, true)];
        }
    }
});

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class, SequenceSeeder::class]);
    $this->useRealPermissions();
});

test('10 §4 setiap permission punya route perwakilan atau tercatat menunggu task-nya (tidak ada yang terlewat)', function () {
    $covered = [...array_keys(REPRESENTATIVE), ...array_keys(PENDING)];

    expect(array_intersect(array_keys(REPRESENTATIVE), array_keys(PENDING)))->toBe([])
        ->and(collect($covered)->sort()->values()->all())->toBe(collect(PermissionCatalog::names())->sort()->values()->all());
});

test('10 §4 matriks role × permission sesuai 06 §4', function (string $role, string $permission, bool $granted) {
    // MFA aktif: role wajib MFA (BR-015) jangan sampai tertahan di halaman aktivasi.
    $user = User::factory()->withTwoFactor()->create()->assignRole($role);
    [$method, $uri] = REPRESENTATIVE[$permission];
    $uri = str_replace('{sequence}', (string) DocumentSequence::query()->value('id'), $uri);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from('/dasbor')
        ->call($method, $uri);

    if ($granted) {
        expect($response->getStatusCode())->toBeLessThan(400, "{$role} seharusnya boleh {$permission} ({$method} {$uri})");
    } elseif ($role === 'pegawai') {
        // BR-017: tanpa menu web sama sekali → dikeluarkan dari web.
        $response->assertRedirect(route('login'));
    } else {
        $response->assertForbidden();
    }
})->with('role_permission');
