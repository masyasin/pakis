<?php

use App\Domain\Access\Support\WebAccess;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 06 §8, §10: menu web dibangun server dari permission; hanya modul yang sudah ada route-nya.
 * `auth.permissions` dibagikan ke frontend.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

/** @return array<string, list<string>> grup → judul item */
function menuOf(string $role): array
{
    $menu = [];
    // MFA aktif: beberapa role wajib MFA (BR-015); yang diuji di sini menu, bukan MFA.
    test()->actingAs(User::factory()->withTwoFactor()->create()->assignRole($role))->get('/dasbor')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$menu) {
            foreach ($page->toArray()['props']['auth']['menu'] as $group) {
                $menu[$group['title'] ?? ''] = array_column($group['items'], 'title');
            }
        });

    return $menu;
}

test('06 §8 owner melihat semua menu yang modulnya sudah ada', function () {
    expect(menuOf('owner'))->toBe([
        '' => ['Dasbor'],
        'Absensi' => ['Shift'],
        'Organisasi' => ['Lokasi', 'Departemen', 'Jabatan', 'Wilayah'],
        'Akses' => ['Pengguna', 'Role & Hak Akses'],
        'Sistem' => ['Pengaturan', 'Penomoran', 'Referensi', 'Audit Log', 'Log Keamanan'],
    ]);
});

test('06 §8 HRD: Pengguna tanpa Role, Pengaturan, Penomoran, Audit', function () {
    expect(menuOf('hrd'))->toBe([
        '' => ['Dasbor'],
        'Absensi' => ['Shift'],
        'Organisasi' => ['Lokasi', 'Departemen', 'Jabatan', 'Wilayah'],
        'Akses' => ['Pengguna'],
        'Sistem' => ['Referensi'],
    ]);
});

test('06 §8 kepala lokasi: Dasbor + Shift (T-033) + Lokasi (T-021) sampai modul absensi/pegawai dibangun', function () {
    expect(menuOf('kepala_lokasi'))->toBe(['' => ['Dasbor'], 'Absensi' => ['Shift'], 'Organisasi' => ['Lokasi']]);
});

test('06 §8 supervisor: hanya Dasbor sampai modul absensi/pegawai dibangun', function () {
    expect(menuOf('supervisor'))->toBe(['' => ['Dasbor']]);
});

test('auth.permissions: super_admin = 50 permission; tamu tanpa menu', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('super_admin'))->get('/dasbor')
        ->assertInertia(fn (Assert $page) => $page->has('auth.permissions', 50)->has('auth.menu'));

    app('auth')->forgetGuards();
    $this->post('/logout');
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('auth.menu', []));
});

test('BR-017 izin web diturunkan dari menu 06 §8 (satu daftar)', function () {
    expect(WebAccess::menuPermissions())->toContain('dashboard.view', 'roles.view', 'audit.view', 'employees.import')
        ->and(WebAccess::menuPermissions())->not->toContain('attendance.self');
});

test('BR-017 pengguna dengan role pegawai saja ditolak masuk web', function () {
    $user = User::factory()->create(['username' => 'rina.pegawai'])->assignRole('pegawai');
    // TestCase memberi dashboard.view ke semua pengguna uji; cabut untuk pegawai murni.
    Gate::define('dashboard.view', fn () => false);

    $this->post('/login', ['login' => 'rina.pegawai', 'password' => 'password'])
        ->assertSessionHasErrors(['login' => WebAccess::MESSAGE]);
    expect($user->can('attendance.self'))->toBeTrue();
});
