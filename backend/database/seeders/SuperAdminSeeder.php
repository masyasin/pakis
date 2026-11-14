<?php

namespace Database\Seeders;

use App\Domain\Access\Support\RoleCatalog;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Akun Super Admin dari env (SEC-008, 04 §4.1). Tidak ada akun/password default: env kosong atau
 * password < 16 karakter → gagal. Akun baru wajib ganti password saat login pertama.
 * Bila akun sudah ada, password TIDAK di-reset (seeder bisa dijalankan ulang dengan aman);
 * hanya role-nya dipastikan.
 */
class SuperAdminSeeder extends Seeder
{
    public const MIN_PASSWORD_LENGTH = 16;

    public function run(): void
    {
        $username = mb_strtolower(trim((string) config('pakis.superadmin.username')));
        $email = trim((string) config('pakis.superadmin.email'));
        $password = (string) config('pakis.superadmin.password');

        if ($username === '' || $email === '' || $password === '') {
            throw new RuntimeException('SuperAdminSeeder: isi SUPERADMIN_USERNAME, SUPERADMIN_EMAIL, dan SUPERADMIN_PASSWORD di .env.');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException('SuperAdminSeeder: SUPERADMIN_PASSWORD minimal '.self::MIN_PASSWORD_LENGTH.' karakter (SEC-008).');
        }

        $user = User::query()->withTrashed()->where('username', $username)->first();

        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => 'Super Admin',
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'must_change_password' => true,
                'is_active' => true,
            ])->save();
        }

        $user->assignRole(RoleCatalog::SUPER_ADMIN);
    }
}
