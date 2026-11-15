<?php

use App\Domain\Access\Support\WebAccess;
use App\Models\User;
use App\Support\Menu\MenuBuilder;
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
        'Unit Kerja' => ['Lokasi', 'Departemen', 'Jabatan'],
        'Kepegawaian' => ['Pegawai', 'Penugasan', 'Impor Pegawai'],
        'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Jadwal', 'Shift', 'Hari Libur', 'Tugas Luar Kota'],
        'Pengguna & Akses' => ['Pengguna', 'Role & Hak Akses', 'Perangkat'],
        'Pengaturan' => ['Pengaturan Umum', 'Penomoran', 'Referensi', 'Wilayah'],
        'Audit' => ['Audit Log', 'Log Keamanan'],
    ]);
});

test('06 §8 HRD: Pengguna tanpa Role, Pengaturan Umum, Penomoran, Audit', function () {
    expect(menuOf('hrd'))->toBe([
        '' => ['Dasbor'],
        'Unit Kerja' => ['Lokasi', 'Departemen', 'Jabatan'],
        'Kepegawaian' => ['Pegawai', 'Penugasan', 'Impor Pegawai'],
        'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Jadwal', 'Shift', 'Hari Libur', 'Tugas Luar Kota'],
        'Pengguna & Akses' => ['Pengguna', 'Perangkat'],
        'Pengaturan' => ['Referensi', 'Wilayah'],
    ]);
});

test('06 §8 kepala lokasi: Dasbor + Jadwal (T-035) + Shift (T-033) + Hari Libur (T-034) + Lokasi (T-021) + Perangkat (T-031) sampai modul presensi/pegawai dibangun', function () {
    expect(menuOf('kepala_lokasi'))->toBe(['' => ['Dasbor'], 'Unit Kerja' => ['Lokasi'], 'Kepegawaian' => ['Pegawai', 'Penugasan'], 'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Jadwal', 'Shift', 'Hari Libur', 'Tugas Luar Kota'], 'Pengguna & Akses' => ['Perangkat']]);
});

test('06 §8 supervisor: Dasbor + Jadwal (T-035) + Hari Libur (T-034) sampai modul presensi/pegawai dibangun', function () {
    expect(menuOf('supervisor'))->toBe(['' => ['Dasbor'], 'Kepegawaian' => ['Pegawai', 'Penugasan'], 'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Jadwal', 'Hari Libur', 'Tugas Luar Kota']]);
});

test('06 §8 setiap grup menu punya seksi sidebar (HRIS / Sistem), Dasbor tanpa seksi', function () {
    $groups = array_values(array_unique(array_filter(array_column(MenuBuilder::ITEMS, 0))));
    expect(array_keys(MenuBuilder::SECTIONS))->toEqualCanonicalizing($groups);

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('owner'))->get('/dasbor')
        ->assertInertia(function (Assert $page) {
            $sections = array_column($page->toArray()['props']['auth']['menu'], 'section', 'title');
            expect($sections)->toMatchArray([
                '' => null, 'Unit Kerja' => 'HRIS', 'Kepegawaian' => 'HRIS', 'Presensi' => 'HRIS',
                'Pengguna & Akses' => 'Sistem', 'Pengaturan' => 'Sistem', 'Audit' => 'Sistem',
            ]);
        });
});

test('auth.permissions: super_admin = 54 permission; tamu tanpa menu', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('super_admin'))->get('/dasbor')
        ->assertInertia(fn (Assert $page) => $page->has('auth.permissions', 54)->has('auth.menu'));

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
