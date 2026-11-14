<?php

use App\Models\User;
use Database\Seeders\DevListingDemoSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;

/*
 * SEC-008: tidak ada akun default. Super Admin dari env dengan password ≥ 16 karakter dan wajib
 * ganti saat login pertama. Seeder demo ditolak di production.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function superAdminEnv(string $password): void
{
    config(['pakis.superadmin' => ['username' => 'Sintech.Admin', 'email' => 'dukungan@sintech-demo.test', 'password' => $password]]);
}

test('SEC-008 SuperAdminSeeder gagal bila env kosong', function () {
    config(['pakis.superadmin' => ['username' => '', 'email' => '', 'password' => '']]);

    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class, 'SUPERADMIN_USERNAME');
    expect(User::query()->count())->toBe(0);
});

test('SEC-008 SuperAdminSeeder gagal bila password 15 karakter', function () {
    superAdminEnv(str_repeat('k', 15));

    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class, 'minimal 16');
    expect(User::query()->count())->toBe(0);
});

test('SEC-008 SuperAdminSeeder 16 karakter: akun super_admin, wajib ganti password, idempoten tanpa reset password', function () {
    superAdminEnv(str_repeat('k', 16));

    $this->seed(SuperAdminSeeder::class);
    $user = User::query()->sole();
    $hash = $user->password;

    expect($user->username)->toBe('sintech.admin')
        ->and($user->must_change_password)->toBeTrue()
        ->and($user->hasRole('super_admin'))->toBeTrue();

    superAdminEnv(str_repeat('z', 20));
    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->count())->toBe(1)
        ->and($user->refresh()->password)->toBe($hash);
});

test('SEC-008 seeder demo ditolak di production', function () {
    $this->app['env'] = 'production';

    // Dipanggil langsung: db:seed di production meminta konfirmasi interaktif lebih dulu.
    expect(fn () => (new DevListingDemoSeeder)->run())->toThrow(RuntimeException::class, 'hanya untuk APP_ENV=local');
    expect(User::query()->count())->toBe(0);
});
