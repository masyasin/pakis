<?php

use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Numbering\Models\DocumentSequence;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SequenceSeeder;
use Tests\Support\FakeOrgStructure;

/*
 * Harness otorisasi (10 §4, T-019): role × permission dari `tests/Fixtures/permission_matrix.php`
 * → satu route perwakilan per permission. Pemegang (✅/🔸) lolos (2xx/3xx), bukan pemegang 403.
 * Role tanpa satu pun menu web (pegawai) dikeluarkan dari web (BR-017) → ke halaman masuk.
 *
 * MODUL BARU: pindahkan permission-nya dari PENDING ke REPRESENTATIVE begitu route-nya ada.
 */

/**
 * permission → [method, uri, data?]. `{sequence}` diganti ID penomoran yang ada; `{target}` diganti
 * akun ber-role `pegawai` yang berada DALAM scope pelaku (agar yang diuji permission, bukan scope 404,
 * dan batas "hak target ⊆ pelaku" UserPolicy terpenuhi).
 */
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
    'users.view' => ['GET', '/pengguna'],
    'users.create' => ['GET', '/pengguna/baru'],
    'users.update' => ['GET', '/pengguna/{target}/ubah'],
    'users.deactivate' => ['PATCH', '/pengguna/{target}/status', ['active' => true]],
    'users.reset_password' => ['POST', '/pengguna/{target}/reset-password', ['current_password' => 'password']],
    'users.reset_mfa' => ['POST', '/pengguna/{target}/reset-mfa'],
    'users.export' => ['GET', '/pengguna/ekspor'],
    'shifts.view' => ['GET', '/shift'],
    'shifts.manage' => ['GET', '/shift/baru'],
    'locations.view' => ['GET', '/lokasi'],
    'locations.manage' => ['GET', '/lokasi/baru'],
    'departments.view' => ['GET', '/departemen'],
    'departments.manage' => ['GET', '/departemen/baru'],
    'positions.view' => ['GET', '/jabatan'],
    'positions.manage' => ['GET', '/jabatan/baru'],
];

/** Permission yang modulnya belum dibangun → task pembuatnya (ditambahkan ke REPRESENTATIVE di sana). */
const PENDING = [
    'employees.view' => 'T-024', 'employees.create' => 'T-024', 'employees.update' => 'T-024', 'employees.delete' => 'T-024',
    'employees.view_sensitive' => 'T-024', 'employees.offboard' => 'T-026', 'employees.import' => 'T-027', 'employees.export' => 'T-024',
    'assignments.view' => 'T-025', 'assignments.manage' => 'T-025',
    'devices.view' => 'T-031', 'devices.manage' => 'T-031',
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
    $data = REPRESENTATIVE[$permission][2] ?? [];
    $uri = str_replace('{sequence}', (string) DocumentSequence::query()->value('id'), $uri);

    if (str_contains($uri, '{target}')) {
        // Pelaku pegawai #1, target pegawai #2 di lokasi yang sama & bawahannya → dalam scope apa pun.
        $org = FakeOrgStructure::bind();
        $org->employee(1)->at(10);
        $org->employee(2)->at(10)->reportsTo(1);
        $user->forceFill(['employee_id' => 1])->save();
        $target = User::factory()->create(['employee_id' => 2])->assignRole('pegawai');
        $uri = str_replace('{target}', (string) $target->id, $uri);
    }

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from('/dasbor')
        ->call($method, $uri, $data);

    if ($granted) {
        expect($response->getStatusCode())->toBeLessThan(400, "{$role} seharusnya boleh {$permission} ({$method} {$uri})");
    } elseif ($role === 'pegawai') {
        // BR-017: tanpa menu web sama sekali → dikeluarkan dari web.
        $response->assertRedirect(route('login'));
    } else {
        $response->assertForbidden();
    }
})->with('role_permission');
