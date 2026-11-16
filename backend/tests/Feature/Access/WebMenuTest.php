<?php

use App\Domain\Access\Support\WebAccess;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Models\User;
use App\Support\Menu\MenuBuilder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ApprovalWorld;

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
        '' => ['Dasbor', 'Persetujuan'],
        'Master' => ['Produk', 'Kategori', 'Lokasi Stok', 'Kendaraan'],
        'Unit Kerja' => ['Lokasi', 'Departemen', 'Jabatan'],
        'Kepegawaian' => ['Pegawai', 'Penugasan', 'Impor Pegawai'],
        'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Koreksi Presensi', 'Jadwal', 'Shift', 'Hari Libur', 'Tugas Luar Kota', 'Perangkat'],
        'Pengguna & Akses' => ['Pengguna', 'Role & Hak Akses'],
        'Pengaturan' => ['Pengaturan Umum', 'Penomoran', 'Referensi', 'Wilayah', 'Aturan Persetujuan'],
        'Audit' => ['Audit Log', 'Log Keamanan'],
    ]);
});

test('06 §8 HRD: Pengguna tanpa Role, Pengaturan Umum, Penomoran, Audit', function () {
    expect(menuOf('hrd'))->toBe([
        '' => ['Dasbor'],
        'Unit Kerja' => ['Lokasi', 'Departemen', 'Jabatan'],
        'Kepegawaian' => ['Pegawai', 'Penugasan', 'Impor Pegawai'],
        'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Koreksi Presensi', 'Jadwal', 'Shift', 'Hari Libur', 'Tugas Luar Kota', 'Perangkat'],
        'Pengguna & Akses' => ['Pengguna'],
        'Pengaturan' => ['Referensi', 'Wilayah'],
    ]);
});

test('06 §8 kepala lokasi: Dasbor + Jadwal (T-035) + Shift (T-033) + Hari Libur (T-034) + Lokasi (T-021) + Perangkat (T-031) sampai modul presensi/pegawai dibangun', function () {
    expect(menuOf('kepala_lokasi'))->toBe(['' => ['Dasbor', 'Persetujuan'], 'Master' => ['Produk', 'Kategori', 'Lokasi Stok', 'Kendaraan'], 'Unit Kerja' => ['Lokasi'], 'Kepegawaian' => ['Pegawai', 'Penugasan'], 'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Koreksi Presensi', 'Jadwal', 'Shift', 'Hari Libur', 'Tugas Luar Kota', 'Perangkat']]);
});

test('06 §8 supervisor: Dasbor + Jadwal (T-035) + Hari Libur (T-034) sampai modul presensi/pegawai dibangun', function () {
    expect(menuOf('supervisor'))->toBe(['' => ['Dasbor', 'Persetujuan'], 'Master' => ['Produk', 'Kategori', 'Lokasi Stok', 'Kendaraan'], 'Kepegawaian' => ['Pegawai', 'Penugasan'], 'Presensi' => ['Presensi Harian', 'Rekap Bulanan', 'Koreksi Presensi', 'Jadwal', 'Hari Libur', 'Tugas Luar Kota']]);
});

test('T-067 role Paket A: Dasbor + Persetujuan (approvals.view) + Master › Produk (T-070), Kategori (T-069), Lokasi Stok & Kendaraan (T-068)', function (string $role, array $top, array $master) {
    expect(menuOf($role))->toBe(['' => $top, 'Master' => $master]);
})->with([
    'admin_penjualan' => ['admin_penjualan', ['Dasbor', 'Persetujuan'], ['Produk', 'Kategori', 'Lokasi Stok', 'Kendaraan']],
    'kepala_gudang' => ['kepala_gudang', ['Dasbor', 'Persetujuan'], ['Produk', 'Kategori', 'Lokasi Stok', 'Kendaraan']],
    'staf_gudang' => ['staf_gudang', ['Dasbor'], ['Produk', 'Kategori', 'Lokasi Stok']],
    'purchasing' => ['purchasing', ['Dasbor', 'Persetujuan'], ['Produk', 'Kategori', 'Lokasi Stok']],
]);

test('15 §6.5 PA-27 menu Persetujuan membawa angka menunggu = tab "Menunggu saya"; item lain tanpa angka', function () {
    $world = ApprovalWorld::make($this);
    $world->rule([['permission', 'purchase_orders.approve']]);
    // purchasing memegang approvals.view (menu tampil) + izin langkah lewat izin langsung.
    $approver = $world->user(permissions: ['purchase_orders.approve']);
    $world->submit($world->user());
    $world->submit($world->user());

    $this->actingAs($approver)->get('/dasbor')->assertOk()
        ->assertInertia(function (Assert $page) {
            $items = collect($page->toArray()['props']['auth']['menu'])->flatMap(fn ($g) => $g['items'])->keyBy('title');
            expect($items['Persetujuan']['badge'])->toBe(2)
                ->and($items['Persetujuan']['href'])->toBe('/persetujuan')
                ->and($items['Dasbor'])->not->toHaveKey('badge');
        });
    expect(app(ApprovalVisibility::class)->pendingCountFor($approver))->toBe(2);
});

test('06 §8 setiap grup menu punya seksi sidebar (Distribusi / HRIS / Sistem), Dasbor tanpa seksi', function () {
    $groups = array_values(array_unique(array_filter(array_column(MenuBuilder::ITEMS, 0))));
    expect(array_keys(MenuBuilder::SECTIONS))->toEqualCanonicalizing($groups);

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('owner'))->get('/dasbor')
        ->assertInertia(function (Assert $page) {
            $sections = array_column($page->toArray()['props']['auth']['menu'], 'section', 'title');
            expect($sections)->toMatchArray([
                '' => null, 'Master' => 'Distribusi', 'Unit Kerja' => 'HRIS', 'Kepegawaian' => 'HRIS', 'Presensi' => 'HRIS',
                'Pengguna & Akses' => 'Sistem', 'Pengaturan' => 'Sistem', 'Audit' => 'Sistem',
            ]);
        });
});

test('auth.permissions: super_admin = 109 permission; tamu tanpa menu', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole('super_admin'))->get('/dasbor')
        ->assertInertia(fn (Assert $page) => $page->has('auth.permissions', 109)->has('auth.menu'));

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
