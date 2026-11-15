<?php

use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-017, BR-030, BR-031: kelola role non-sistem + matriks permission.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function userWithRole(string ...$roles): User
{
    // MFA aktif: owner/administrator/hrd/super_admin wajib MFA (BR-015); test ini menguji role, bukan MFA.
    return User::factory()->withTwoFactor()->create()->assignRole($roles);
}

function confirmed()
{
    return test()->withSession(['auth.password_confirmed_at' => time()]);
}

function rolePayload(array $overrides = []): array
{
    return [
        'label' => 'Kepala Toko Khusus',
        'name' => 'kepala_toko_khusus',
        'description' => 'Contoh role uji',
        'data_scope' => 'location',
        'mfa_required' => false,
        'permissions' => ['dashboard.view', 'employees.view', 'attendance.view'],
        ...$overrides,
    ];
}

test('daftar role butuh roles.view; administrator boleh, HRD tidak (06 §4)', function () {
    $this->actingAs(userWithRole('hrd'))->get('/role')->assertForbidden();

    $this->actingAs(userWithRole('administrator'))->get('/role')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('roles/index')->has('rows', 7));
});

test('form role butuh konfirmasi password (06 §1.6)', function () {
    $this->actingAs(userWithRole('owner'))->get('/role/baru')->assertRedirect(route('password.confirm'));
});

test('FR-017 buat role: matriks tersimpan, audit role.created + event role_changed & permission_changed', function () {
    $this->actingAs(userWithRole('owner'));

    confirmed()->post('/role', rolePayload())->assertSessionHasNoErrors()->assertRedirect('/role');

    $role = Role::findByName('kepala_toko_khusus', 'web');
    expect($role->label)->toBe('Kepala Toko Khusus')
        ->and($role->is_system)->toBeFalse()
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['attendance.view', 'dashboard.view', 'employees.view']);

    expect(AuditLog::query()->where('event', 'role.created')->exists())->toBeTrue()
        ->and(SecurityEvent::query()->where('event', 'role_changed')->exists())->toBeTrue()
        ->and(SecurityEvent::query()->where('event', 'permission_changed')->sole()->context['added'])
        ->toBe(['attendance.view', 'dashboard.view', 'employees.view']);
});

test('BR-030 validasi kode, label, dan permission hanya dari katalog', function (array $overrides, string $field) {
    $this->actingAs(userWithRole('owner'));

    confirmed()->from('/role/baru')->post('/role', rolePayload($overrides))->assertSessionHasErrors($field);
})->with([
    'kode huruf besar' => [['name' => 'Kepala_Toko'], 'name'],
    'kode terlalu pendek' => [['name' => 'ab'], 'name'],
    'kode sudah dipakai' => [['name' => 'hrd'], 'name'],
    'label terlalu pendek' => [['label' => 'ab'], 'label'],
    'permission karangan' => [['permissions' => ['dashboard.view', 'semua.boleh']], 'permissions.1'],
    'scope tidak dikenal' => [['data_scope' => 'galaxy'], 'data_scope'],
]);

test('BR-031 perubahan permission langsung berlaku (cache Spatie dibersihkan) + event permission_changed added/removed', function () {
    $hrdUser = userWithRole('hrd');
    expect($hrdUser->can('holidays.manage'))->toBeTrue();

    $this->actingAs(userWithRole('owner'));
    $hrd = Role::findByName('hrd', 'web');
    $permissions = $hrd->permissions->pluck('name')->reject(fn ($p) => $p === 'holidays.manage')->push('audit.view')->values()->all();

    confirmed()->put("/role/{$hrd->id}", rolePayload(['label' => 'HRD', 'name' => 'hrd', 'data_scope' => 'all', 'mfa_required' => true, 'permissions' => $permissions]))
        ->assertSessionHasNoErrors();

    $fresh = User::query()->findOrFail($hrdUser->id);
    expect($fresh->can('holidays.manage'))->toBeFalse()
        ->and($fresh->can('audit.view'))->toBeTrue();

    $context = SecurityEvent::query()->where('event', 'permission_changed')->sole()->context;
    expect($context['added'])->toBe(['audit.view'])->and($context['removed'])->toBe(['holidays.manage']);
});

test('BR-031 kode role sistem tidak bisa diganti', function () {
    $this->actingAs(userWithRole('owner'));
    $pegawai = Role::findByName('pegawai', 'web');

    confirmed()->from("/role/{$pegawai->id}/ubah")->put("/role/{$pegawai->id}", rolePayload(['name' => 'pegawai_baru', 'data_scope' => 'self', 'permissions' => ['attendance.self']]))
        ->assertSessionHasErrors('name');

    expect(Role::query()->where('name', 'pegawai')->exists())->toBeTrue();
});

test('BR-031 owner & super_admin terkunci penuh: ubah/hapus ditolak, form baca-saja', function (string $locked) {
    $this->actingAs(userWithRole('super_admin'));
    $role = Role::findByName($locked, 'web');

    confirmed()->put("/role/{$role->id}", rolePayload(['name' => $locked, 'permissions' => ['dashboard.view']]))->assertForbidden();
    confirmed()->delete("/role/{$role->id}")->assertForbidden();
    confirmed()->get("/role/{$role->id}/ubah")->assertOk()->assertInertia(fn (Assert $page) => $page->where('readOnly', 'locked'));

    expect($role->fresh()->permissions)->toHaveCount(54);
})->with(['owner', 'super_admin']);

test('BR-031 pengguna tidak bisa mengubah role yang sedang ia pegang (cegah naik hak sendiri)', function () {
    $admin = userWithRole('administrator');
    $this->actingAs($admin);
    $role = Role::findByName('administrator', 'web');

    confirmed()->get("/role/{$role->id}/ubah")->assertOk()->assertInertia(fn (Assert $page) => $page->where('readOnly', 'own'));
    confirmed()->put("/role/{$role->id}", rolePayload([
        'label' => 'Administrator', 'name' => 'administrator', 'data_scope' => 'all', 'mfa_required' => true,
        'permissions' => [...$role->permissions->pluck('name')->all(), 'employees.view_sensitive'],
    ]))->assertForbidden();

    expect($admin->fresh()->can('employees.view_sensitive'))->toBeFalse();
});

test('BR-031 role sistem atau role yang masih dipakai pengguna tidak bisa dihapus; role kosong bisa', function () {
    $this->actingAs(userWithRole('owner'));
    confirmed()->post('/role', rolePayload())->assertSessionHasNoErrors();
    $custom = Role::findByName('kepala_toko_khusus', 'web');

    $pegawai = Role::findByName('pegawai', 'web');
    confirmed()->from('/role')->delete("/role/{$pegawai->id}")->assertSessionHasErrors('role');

    $holder = userWithRole('kepala_toko_khusus');
    confirmed()->from('/role')->delete("/role/{$custom->id}")->assertSessionHasErrors('role');
    expect(Role::query()->whereKey($custom->id)->exists())->toBeTrue();

    $holder->removeRole('kepala_toko_khusus');
    confirmed()->delete("/role/{$custom->id}")->assertSessionHasNoErrors();

    expect(Role::query()->whereKey($custom->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'role.deleted')->exists())->toBeTrue();
});

test('tanpa roles.manage tidak bisa membuat role', function () {
    $this->actingAs(userWithRole('hrd'));

    confirmed()->post('/role', rolePayload())->assertForbidden();
    expect(Role::query()->where('name', 'kepala_toko_khusus')->exists())->toBeFalse();
});

test('listing role: cari, filter scope & sistem, sort label', function () {
    $this->actingAs(userWithRole('owner'));

    $this->get('/role?q=kepala')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'kepala_lokasi'));
    $this->get('/role?filter[data_scope][]=team')->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.name', 'supervisor'));
    $this->get('/role?filter[is_system]=1')->assertInertia(fn (Assert $page) => $page->has('rows', 3));
    $this->get('/role?sort=-label')->assertInertia(fn (Assert $page) => $page->where('rows.0.name', 'supervisor'));
});

test('BR-031 batas delegasi: Administrator tidak bisa memberi permission yang tidak ia miliki (cegah naik hak lewat akun lain)', function () {
    $this->actingAs(userWithRole('administrator'));

    confirmed()->from('/role/baru')->post('/role', rolePayload(['permissions' => ['dashboard.view', 'employees.view_sensitive']]))
        ->assertSessionHasErrors('permissions');
    expect(Role::query()->where('name', 'kepala_toko_khusus')->exists())->toBeFalse();

    // Mengubah role lain: permission yang SUDAH ada (di luar milik admin) boleh tetap / dilepas, tidak boleh ditambah.
    $hrd = Role::findByName('hrd', 'web');
    $kept = $hrd->permissions->pluck('name')->all();
    confirmed()->put("/role/{$hrd->id}", rolePayload(['label' => 'HRD', 'name' => 'hrd', 'data_scope' => 'all', 'mfa_required' => true, 'permissions' => $kept]))
        ->assertSessionHasNoErrors();

    $supervisor = Role::findByName('supervisor', 'web');
    confirmed()->put("/role/{$supervisor->id}", rolePayload([
        'label' => 'Supervisor', 'name' => 'supervisor', 'data_scope' => 'team',
        'permissions' => [...$supervisor->permissions->pluck('name')->all(), 'employees.view_sensitive'],
    ]))->assertSessionHasErrors('permissions');
});

test('BR-031 batas delegasi scope: tidak bisa memberi scope lebih luas dari scope sendiri', function () {
    // Role kustom pengelola role dengan scope `location`.
    $manager = Role::create(['name' => 'pengelola_lokasi', 'guard_name' => 'web', 'label' => 'Pengelola Lokasi', 'data_scope' => 'location']);
    $manager->givePermissionTo(['dashboard.view', 'roles.view', 'roles.manage']);
    $this->actingAs(userWithRole('pengelola_lokasi'));

    confirmed()->from('/role/baru')->post('/role', rolePayload(['data_scope' => 'all', 'permissions' => ['dashboard.view']]))
        ->assertSessionHasErrors('data_scope');
    confirmed()->post('/role', rolePayload(['data_scope' => 'team', 'permissions' => ['dashboard.view']]))
        ->assertSessionHasNoErrors();
});
