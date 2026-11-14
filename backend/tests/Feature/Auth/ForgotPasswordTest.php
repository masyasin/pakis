<?php

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Access\Notifications\ResetPasswordLink;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * FR-005, BR-012, BR-016, SEC-004: lupa password lewat email (tautan sekali pakai).
 */

const GENERIC_SENT = 'Jika akun ditemukan, kami sudah mengirim petunjuk reset.';
const INVALID_LINK = 'Tautan reset tidak berlaku atau sudah kedaluwarsa. Minta tautan baru.';

beforeEach(function () {
    Notification::fake();
    setSetting('security.password_check_breached', false);
    $this->user = User::factory()->create(['username' => 'siti.aminah', 'email' => 'siti@pakis-demo.test']);
});

function requestReset(string $login, string $channel = 'email', string $ip = '10.0.0.5')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from('/forgot-password')
        ->post('/forgot-password', ['login' => $login, 'channel' => $channel]);
}

function sentToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
        $token = $n->token;

        return true;
    });

    return (string) $token;
}

function resetWith(string $token, string $email, string $password = 'kopi pagi di gudang')
{
    return test()->from('/reset-password/'.$token)->post('/reset-password', [
        'token' => $token, 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
    ]);
}

test('halaman lupa password: field login, kanal aktif saja, teks akun tanpa email', function () {
    $this->get('/forgot-password')->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/forgot-password')
        ->where('channels', [['value' => 'email', 'label' => 'Email']]));
});

test('SEC-004 respons identik: akun ada, tidak ada, dan tanpa email', function () {
    $noEmail = User::factory()->create(['username' => 'budi.tanpa.email', 'email' => null]);

    foreach (['siti.aminah', 'tidak.ada.orangnya', 'budi.tanpa.email'] as $login) {
        requestReset($login)->assertRedirect('/forgot-password')->assertSessionHas('status', GENERIC_SENT)->assertSessionHasNoErrors();
    }

    Notification::assertSentTo($this->user, ResetPasswordLink::class);
    Notification::assertNotSentTo($noEmail, ResetPasswordLink::class);
    Notification::assertCount(1);
    expect(SecurityEvent::query()->where('event', 'password_reset_requested')->count())->toBe(3);
});

test('BR-010 permintaan bisa memakai email atau username tanpa beda huruf', function () {
    requestReset(' SITI@Pakis-Demo.test ');

    Notification::assertSentTo($this->user, ResetPasswordLink::class);
});

test('SEC-004 tautan selalu berasal dari APP_URL, bukan Host header permintaan', function () {
    $this->withHeader('Host', 'penipu.test')->post('/forgot-password', ['login' => 'siti.aminah', 'channel' => 'email']);

    Notification::assertSentTo($this->user, ResetPasswordLink::class, function (ResetPasswordLink $n) {
        $url = $n->toMail($this->user)->actionUrl;

        return str_starts_with($url, rtrim(config('app.url'), '/').'/reset-password/') && ! str_contains($url, 'penipu.test');
    });
});

test('BR-012 reset sukses: password baru, efek lengkap, sesi lama keluar', function () {
    $this->user->update(['must_change_password' => true]);
    requestReset('siti.aminah');

    resetWith(sentToken($this->user), 'siti@pakis-demo.test')->assertRedirect(route('login'))->assertSessionHasNoErrors();

    $this->user->refresh();
    expect(Hash::check('kopi pagi di gudang', $this->user->password))->toBeTrue()
        ->and($this->user->must_change_password)->toBeFalse()
        ->and($this->user->password_changed_at)->not->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'password_reset_completed')->where('user_id', $this->user->id)->exists())->toBeTrue();
    Notification::assertSentTo($this->user, PasswordChanged::class);
});

test('SEC-004 token dipakai dua kali → gagal', function () {
    requestReset('siti.aminah');
    $token = sentToken($this->user);

    resetWith($token, 'siti@pakis-demo.test')->assertSessionHasNoErrors();
    resetWith($token, 'siti@pakis-demo.test', 'teh sore di toko kalirejo')->assertSessionHasErrors(['email' => INVALID_LINK]);

    expect(Hash::check('kopi pagi di gudang', $this->user->refresh()->password))->toBeTrue();
});

test('BR-012 token kedaluwarsa mengikuti setting reset_token_minutes', function () {
    setSetting('security.reset_token_minutes', 15);
    requestReset('siti.aminah');
    $token = sentToken($this->user);

    $this->travel(16)->minutes();
    resetWith($token, 'siti@pakis-demo.test')->assertSessionHasErrors(['email' => INVALID_LINK]);
});

test('SEC-004 aturan password baru diperiksa SETELAH token sah (tidak bisa menebak password lama)', function () {
    $this->user->update(['password' => 'kopi pagi di gudang']);

    // Token palsu + password sama dengan yang lama: jawabannya tetap "tautan tidak berlaku",
    // bukan "password sama dengan password saat ini".
    resetWith('token-palsu', 'siti@pakis-demo.test', 'kopi pagi di gudang')
        ->assertSessionHasErrors(['email' => INVALID_LINK])
        ->assertSessionDoesntHaveErrors('password');
});

test('BR-011 kebijakan password berlaku saat reset dengan token sah', function () {
    requestReset('siti.aminah');

    resetWith(sentToken($this->user), 'siti@pakis-demo.test', 'siti.aminah-2026')
        ->assertSessionHasErrors(['password' => 'Password tidak boleh memuat username Anda.']);
});

test('BR-016 maksimal 3 permintaan per identifier per jam, pesan sama untuk identifier tak ada', function () {
    foreach (range(1, 3) as $i) {
        requestReset('siti.aminah', ip: "10.0.1.{$i}")->assertSessionHas('status', GENERIC_SENT);
        requestReset('tidak.ada', ip: "10.0.2.{$i}")->assertSessionHas('status', GENERIC_SENT);
    }

    requestReset('SITI.aminah', ip: '10.0.1.9')->assertSessionHasErrors(['login' => 'Terlalu banyak permintaan. Coba lagi nanti.']);
    requestReset('tídak.ada', ip: '10.0.2.9')->assertSessionHasErrors(['login' => 'Terlalu banyak permintaan. Coba lagi nanti.']);

    $this->travel(61)->minutes();
    requestReset('siti.aminah', ip: '10.0.1.9')->assertSessionHas('status', GENERIC_SENT);
});

test('BR-016 maksimal 10 permintaan per jaringan per jam', function () {
    foreach (range(1, 10) as $i) {
        requestReset("orang{$i}")->assertSessionHas('status', GENERIC_SENT);
    }

    requestReset('orang11')->assertSessionHasErrors(['login' => 'Terlalu banyak permintaan. Coba lagi nanti.']);
});

test('kanal yang tidak aktif ditolak', function () {
    requestReset('siti.aminah', 'whatsapp')->assertSessionHasErrors('channel');
    Notification::assertNothingSent();
});

test('BR-012 email belum terverifikasi tidak dikirimi tautan reset; respons tetap generik', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();

    requestReset('siti.aminah')->assertSessionHas('status', GENERIC_SENT)->assertSessionHasNoErrors();

    Notification::assertNothingSent();
    expect(SecurityEvent::query()->where('event', 'password_reset_requested')->count())->toBe(1);
});
