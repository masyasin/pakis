<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
 * Fitur bawaan starter kit yang tidak ada di spesifikasi PAKIS ONE.
 * - Pendaftaran mandiri: 05 §1 hanya memuat /login, /forgot-password, /reset-password, /two-factor-challenge.
 *   Akun dibuat admin (T-020).
 * - Passkeys: bukan bagian alur login + lockout + MFA per role (T-013, T-016). Keputusan 14 Nov 2026.
 */

test('pendaftaran mandiri tidak tersedia', function () {
    expect(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Penyusup',
        'email' => 'penyusup@example.com',
        'password' => 'password-panjang-sekali',
        'password_confirmation' => 'password-panjang-sekali',
    ])->assertNotFound();

    $this->assertDatabaseCount('users', 0);
});

test('login passkey tidak tersedia', function () {
    $passkeyRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'passkey'))
        ->map(fn ($route) => $route->uri())
        ->values();

    expect($passkeyRoutes->all())->toBe([]);
    expect(Schema::hasTable('passkeys'))->toBeFalse();
});
