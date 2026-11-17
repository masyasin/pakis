<?php

use App\Models\User;
use Database\Seeders\E2eUserSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * T-087 — akun uji E2E Playwright: rahasia hanya dari config/env (CLAUDE.md §6), ditolak di
 * produksi, idempoten. Nilai di test ini fiktif dan hanya hidup di memori proses test.
 */

test('CLAUDE.md §6 E2eUserSeeder ditolak di produksi dan tanpa rahasia dari env', function () {
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => (new E2eUserSeeder)->run())->toThrow(RuntimeException::class);
    app()->detectEnvironment(fn () => 'testing');

    config(['pakis.e2e.password' => '', 'pakis.e2e.totp_secret' => '']);
    expect(fn () => (new E2eUserSeeder)->run())->toThrow(RuntimeException::class);
    expect(User::query()->where('username', E2eUserSeeder::USERNAME)->exists())->toBeFalse();
});

test('T-087 E2eUserSeeder membuat Owner ber-2FA terkonfirmasi dari config; idempoten', function () {
    $password = str_repeat('Uji', 5);
    $secret = str_repeat('ABCDEFGH', 2);
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    config(['pakis.e2e.password' => $password, 'pakis.e2e.totp_secret' => $secret]);

    (new E2eUserSeeder)->run();
    (new E2eUserSeeder)->run();

    $user = User::query()->where('username', E2eUserSeeder::USERNAME)->sole();
    expect($user->hasRole('owner'))->toBeTrue()
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and(decrypt($user->two_factor_secret))->toBe($secret)
        ->and($user->two_factor_confirmed_at)->not->toBeNull()
        ->and($user->must_change_password)->toBeFalse();
});
