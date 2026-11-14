<?php

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
 * FR-004 / BR-011: ubah password sendiri (form Pengaturan akun; route dipindah ke /profil di T-017).
 */

beforeEach(function () {
    Notification::fake();
    setSetting('security.password_check_breached', false);
    $this->user = User::factory()->create(['username' => 'siti.aminah']);
});

function loginWeb(string $password = 'password'): void
{
    test()->post(route('login.store'), ['login' => 'siti.aminah', 'password' => $password])
        ->assertRedirect(route('dashboard', absolute: false));
}

function changeOwnPassword(string $current, string $new)
{
    return test()->put(route('user-password.update'), [
        'current_password' => $current,
        'password' => $new,
        'password_confirmation' => $new,
    ]);
}

test('FR-004 ubah password: wajib password saat ini, event + email, tanpa password di log', function () {
    loginWeb();

    changeOwnPassword('salah', 'kopi pagi di gudang')->assertSessionHasErrors('current_password');

    changeOwnPassword('password', 'kopi pagi di gudang')->assertSessionHasNoErrors();

    $event = SecurityEvent::query()->where('event', 'password_changed')->sole();
    expect(json_encode($event->context))->not->toContain('kopi pagi');
    Notification::assertSentTo($this->user, PasswordChanged::class);
});

test('BR-011 kebijakan berlaku di form ubah password', function () {
    loginWeb();

    changeOwnPassword('password', 'xx-siti.aminah-xx')
        ->assertSessionHasErrors(['password' => 'Password tidak boleh memuat username Anda.']);
});

test('ubah password: sesi ini tetap masuk, sesi lain (hash password lama) dikeluarkan', function () {
    loginWeb();
    changeOwnPassword('password', 'kopi pagi di gudang')->assertSessionHasNoErrors();

    // Sesi ini tetap aktif setelah mengganti password.
    $this->get('/dashboard')->assertOk();

    // Sesi lain: masih menyimpan sidik jari hash password LAMA → keluar di request berikutnya.
    $oldHash = auth()->guard('web')->hashPasswordForCookie(User::factory()->make()->password);
    app('auth')->forgetGuards();
    $this->withSession(['password_hash_web' => $oldHash])->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});
