<?php

use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Device\Models\Device;
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
 * dan batas "hak target ⊆ pelaku" UserPolicy terpenuhi). `{device}` = perangkat pending milik target.
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
    'holidays.view' => ['GET', '/hari-libur'],
    'holidays.manage' => ['GET', '/hari-libur/baru'],
    'schedules.view' => ['GET', '/jadwal'],
    // Payload kosong: pemegang izin lolos ke validasi (302), bukan pemegang 403.
    'schedules.manage' => ['POST', '/jadwal/pengecualian'],
    'field_trips.view' => ['GET', '/tugas-luar-kota'],
    'field_trips.manage' => ['GET', '/tugas-luar-kota/baru'],
    'locations.view' => ['GET', '/lokasi'],
    'locations.manage' => ['GET', '/lokasi/baru'],
    'departments.view' => ['GET', '/departemen'],
    'departments.manage' => ['GET', '/departemen/baru'],
    'positions.view' => ['GET', '/jabatan'],
    'positions.manage' => ['GET', '/jabatan/baru'],
    'employees.view' => ['GET', '/pegawai'],
    'employees.create' => ['GET', '/pegawai/baru'],
    'employees.update' => ['GET', '/pegawai/{employee}/ubah'],
    'employees.delete' => ['DELETE', '/pegawai/{employee}'],
    'employees.view_sensitive' => ['POST', '/pegawai/{employee}/nik'],
    'employees.export' => ['GET', '/pegawai/ekspor'],
    'assignments.view' => ['GET', '/penugasan'],
    'assignments.manage' => ['POST', '/pegawai/{employee}/penugasan'],
    'employees.offboard' => ['POST', '/pegawai/{employee}/nonaktifkan'],
    'employees.import' => ['GET', '/impor'],
    'devices.view' => ['GET', '/perangkat'],
    'devices.manage' => ['POST', '/perangkat/{device}/tolak', ['reason' => 'Bukan HP kantor']],
    'attendance.view' => ['GET', '/absensi'],
    'attendance.export' => ['GET', '/absensi/ekspor'],
    // T-067: tab "Menunggu saya" terbuka untuk calon penyetuju (PA-27); tab "Semua" butuh approvals.view.
    'approvals.view' => ['GET', '/persetujuan?filter[tab]=all'],
    'approvals.configure' => ['GET', '/pengaturan/aturan-persetujuan'],
    // T-068: daftar ber-scope Stok (tanpa pegawai = hanya TRANSIT, tetap 200); form tambah butuh `.manage`.
    'stock_locations.view' => ['GET', '/lokasi-stok'],
    'stock_locations.manage' => ['GET', '/lokasi-stok/baru'],
    'vehicles.view' => ['GET', '/kendaraan'],
    'vehicles.manage' => ['GET', '/kendaraan/baru'],
    // T-074: antrean verifikasi kios (scope Pelanggan; tanpa kios dalam scope tetap 200).
    'customers.verify' => ['GET', '/pelanggan/verifikasi'],
];

/** Permission yang modulnya belum dibangun → task pembuatnya (ditambahkan ke REPRESENTATIVE di sana). */
const PENDING = [
    // `approve` sudah punya route API (T-040); route web perwakilannya dibuat T-041.
    'attendance_corrections.view' => 'T-041', 'attendance_corrections.approve' => 'T-041',
    'field_trips.request' => 'T-036A bagian 2', 'field_trips.approve' => 'T-036A bagian 2',
    // Paket A: izin dibuat T-067 (15 §6.3), route-nya dibangun task modulnya (TASKS_PAKET_A.md).
    'products.view' => 'T-070', 'products.manage' => 'T-070', 'product_registrations.manage' => 'T-071',
    'suppliers.view' => 'T-072', 'suppliers.manage' => 'T-072',
    'sales_areas.view' => 'T-073', 'sales_areas.manage' => 'T-073',
    'customers.view' => 'T-073', 'customers.export' => 'T-073', 'customers.manage' => 'T-073',
    'customers.merge' => 'T-074', 'customers.change_phone' => 'T-074',
    'customers.change_phone_approve' => 'T-074', 'credit_limits.set' => 'T-074', 'credit_limits.set_high' => 'T-074',
    'prices.view' => 'T-075', 'prices.manage' => 'T-075', 'prices.publish' => 'T-075',
    'promotions.view' => 'T-076', 'promotions.manage' => 'T-076', 'promotions.approve' => 'T-076',
    'purchase_orders.view' => 'T-079', 'purchase_orders.create' => 'T-079', 'purchase_orders.approve' => 'T-079',
    'purchase_orders.approve_high' => 'T-079', 'goods_receipts.view' => 'T-080', 'goods_receipts.create' => 'T-080',
    'goods_receipts.approve_short_shelf_life' => 'T-080', 'purchase_returns.manage' => 'T-081',
    'purchase_returns.approve' => 'T-081',
    'stock.view' => 'T-077', 'stock.view_cost' => 'T-077', 'stock.export' => 'T-077',
    'stock_transfers.view' => 'T-082', 'stock_transfers.create' => 'T-082', 'stock_transfers.receive' => 'T-082',
    'stock_transfers.approve_variance' => 'T-082', 'stock_opnames.view' => 'T-083', 'stock_opnames.manage' => 'T-083',
    'stock_opnames.approve_variance' => 'T-083', 'stock_opnames.approve_variance_high' => 'T-083',
    'stock_quarantine.manage' => 'T-084', 'stock_quarantine.release' => 'T-084',
    'stock_disposals.manage' => 'T-084', 'stock_disposals.approve' => 'T-084',
    'serials.manage' => 'T-080', 'serials.approve_swap' => 'T-080',
    'master_imports.manage' => 'T-085',
];

/**
 * Permission khusus API Pakis Pro (token + perangkat terikat) → harness web di sini tidak bisa
 * memanggilnya; diuji di test API-nya (permission → berkas test).
 */
const API_ONLY = [
    'attendance.self' => 'tests/Feature/Api/V1/Attendance/AttendanceApiTest.php',
    'attendance_corrections.create' => 'tests/Feature/Api/V1/Attendance/CorrectionApiTest.php',
];

/**
 * Permission yang route-nya butuh syarat lain selain izin (mis. URL bertanda tangan) → di sini
 * pemegang & bukan pemegang sama-sama ditolak; diuji per role di berkas test-nya.
 */
const DEDICATED = [
    'attendance.view_photo' => 'tests/Feature/Attendance/AttendanceWebTest.php',
];

dataset('role_permission', function () {
    // Dataset dievaluasi sebelum aplikasi menyala: path relatif, bukan base_path().
    $matrix = require __DIR__.'/../../Fixtures/permission_matrix.php';
    $matrix['super_admin'] = PermissionCatalog::names();

    foreach ($matrix as $role => $granted) {
        // Fixture = sel Fase 0 (06 §4); sel Paket A (15 §6.4) dari RoleCatalog, yang dijaga
        // PaketAMatrixTest identik dengan dokumen. Role Paket A tanpa baris fixture ikut di bawah.
        $granted = [...$granted, ...RoleCatalog::paketAPermissionsFor($role)];
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
    $covered = [...array_keys(REPRESENTATIVE), ...array_keys(PENDING), ...array_keys(API_ONLY), ...array_keys(DEDICATED)];

    expect(array_intersect(array_keys(REPRESENTATIVE), array_keys(PENDING)))->toBe([])
        ->and(collect(DEDICATED)->every(fn (string $file) => is_file(base_path($file))))->toBeTrue()
        ->and(array_intersect(array_keys(REPRESENTATIVE), array_keys(API_ONLY)))->toBe([])
        ->and(collect(API_ONLY)->every(fn (string $file) => is_file(base_path($file))))->toBeTrue()
        ->and(collect($covered)->sort()->values()->all())->toBe(collect(PermissionCatalog::names())->sort()->values()->all());
});

test('10 §4 matriks role × permission sesuai 06 §4', function (string $role, string $permission, bool $granted) {
    // MFA aktif: role wajib MFA (BR-015) jangan sampai tertahan di halaman aktivasi.
    $user = User::factory()->withTwoFactor()->create()->assignRole($role);
    [$method, $uri] = REPRESENTATIVE[$permission];
    $data = REPRESENTATIVE[$permission][2] ?? [];
    $uri = str_replace('{sequence}', (string) DocumentSequence::query()->value('id'), $uri);

    if (str_contains($uri, '{target}') || str_contains($uri, '{employee}') || str_contains($uri, '{device}')) {
        // Pelaku pegawai #1, target pegawai #2 di lokasi yang sama & bawahannya → dalam scope apa pun.
        $org = FakeOrgStructure::bind();
        $org->employee(1)->at(10);
        $org->employee(2)->at(10)->reportsTo(1);
        $user->forceFill(['employee_id' => employeeRow(1)])->save();
        $target = User::factory()->create(['employee_id' => employeeRow(2)])->assignRole('pegawai');
        // `{device}` = HP target yang menunggu persetujuan (T-031).
        $device = Device::query()->create(['user_id' => $target->id, 'device_uid' => str_repeat('a', 64), 'status' => 'pending']);
        $uri = str_replace(['{target}', '{employee}', '{device}'], [(string) $target->id, '2', (string) $device->id], $uri);
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
