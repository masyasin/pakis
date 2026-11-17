<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Akun uji E2E Playwright (T-087, `tests/e2e`): satu Owner ber-MFA untuk alur web Paket A. Password
 * dan rahasia TOTP TIDAK ditulis di kode (CLAUDE.md §6): dibaca dari env `E2E_PASSWORD` dan
 * `E2E_TOTP_SECRET` (base32) yang sama dengan yang dipakai Playwright. Ditolak di produksi.
 *
 *     $env:E2E_PASSWORD='…'; $env:E2E_TOTP_SECRET='…'; php artisan db:seed --class=E2eUserSeeder
 */
class E2eUserSeeder extends Seeder
{
    public const USERNAME = 'e2e.owner';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('E2eUserSeeder ditolak di produksi.');
        }
        $password = (string) config('pakis.e2e.password');
        $secret = strtoupper((string) config('pakis.e2e.totp_secret'));
        if (mb_strlen($password) < 12 || preg_match('/^[A-Z2-7]{16,}$/', $secret) !== 1) {
            throw new RuntimeException('Isi E2E_PASSWORD (min 12 karakter) dan E2E_TOTP_SECRET (base32, min 16 karakter) di env.');
        }

        $user = User::query()->where('username', self::USERNAME)->first() ?? new User;
        $user->forceFill([
            'name' => 'Uji E2E Owner', 'username' => self::USERNAME, 'email' => 'e2e.owner@pakis-demo.test',
            'password' => Hash::make($password), 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now(),
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode([])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $user->syncRoles(['owner']);
    }
}
