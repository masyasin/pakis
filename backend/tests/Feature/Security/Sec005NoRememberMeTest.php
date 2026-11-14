<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
 * SEC-005: tanpa "ingat saya". Sesi web harus berakhir sesuai idle/absolute timeout (BR-018),
 * jadi tidak boleh ada cookie recaller yang menghidupkan sesi kembali.
 */

test('SEC-005 tabel users tidak punya kolom remember_token', function () {
    expect(Schema::hasColumn('users', 'remember_token'))->toBeFalse();
});

test('SEC-005 login dengan remember=on tidak menerbitkan cookie recaller', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $this->assertAuthenticated();

    $recallers = collect($response->headers->getCookies())
        ->filter(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_'));

    expect($recallers)->toBeEmpty();
});

test('SEC-005 logout tidak gagal walau model tanpa remember token', function () {
    $user = User::factory()->create();

    // SessionGuard::logout() memanggil cycleRememberToken(); harus no-op, bukan menulis kolom yang tidak ada.
    $this->actingAs($user)->post(route('logout'))->assertRedirect();

    $this->assertGuest();
});
