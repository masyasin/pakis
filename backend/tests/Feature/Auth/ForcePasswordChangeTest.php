<?php

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-007 (sisi pengguna) / SEC-006: akun `must_change_password` wajib mengganti password dulu.
 */

beforeEach(function () {
    Notification::fake();
    setSetting('security.password_check_breached', false);
    $this->user = User::factory()->create(['username' => 'siti.aminah', 'must_change_password' => true]);
    Gate::define('audit.view', fn () => true);
    Gate::define('regions.view', fn () => true);
});

test('SEC-006 dari route mana pun diarahkan ke /ganti-password', function (string $url) {
    $this->actingAs($this->user)->get($url)->assertRedirect('/ganti-password');
})->with(['/dashboard', '/profil', '/audit', '/wilayah', '/unduhan']);

test('SEC-006 request Inertia mendapat 409 + lokasi /ganti-password', function () {
    $this->actingAs($this->user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->get('/dashboard')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/ganti-password'));
});

test('halaman ganti password tampil dan logout tetap bisa', function () {
    $this->actingAs($this->user)->get('/ganti-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/force-change-password')
            ->where('username', 'siti.aminah')
            ->where('minLength', 10));

    $this->actingAs($this->user)->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});

test('akun tanpa kewajiban tidak dipaksa dan tidak bisa membuka /ganti-password', function () {
    $this->user->update(['must_change_password' => false]);

    $this->actingAs($this->user)->get('/dashboard')->assertOk();
    $this->actingAs($this->user)->get('/ganti-password')->assertRedirect(route('dashboard'));
});

test('FR-007 simpan password baru: kewajiban selesai, event password_changed, email, ke dasbor', function () {
    $this->actingAs($this->user)
        ->put('/ganti-password', ['password' => 'kopi pagi di gudang', 'password_confirmation' => 'kopi pagi di gudang'])
        ->assertRedirect(route('dashboard'));

    $this->user->refresh();
    expect($this->user->must_change_password)->toBeFalse()
        ->and($this->user->password_changed_at)->not->toBeNull()
        ->and(Hash::check('kopi pagi di gudang', $this->user->password))->toBeTrue();

    $event = SecurityEvent::query()->where('event', 'password_changed')->sole();
    expect($event->user_id)->toBe($this->user->id)
        ->and(json_encode($event->context))->not->toContain('kopi pagi');
    Notification::assertSentTo($this->user, PasswordChanged::class);

    $this->actingAs($this->user)->get('/dashboard')->assertOk();
});

test('BR-011 di halaman ganti password: username atau password lama ditolak', function () {
    $this->actingAs($this->user)
        ->put('/ganti-password', ['password' => 'siti.aminah-baru', 'password_confirmation' => 'siti.aminah-baru'])
        ->assertSessionHasErrors(['password' => 'Password tidak boleh memuat username Anda.']);

    // Password diganti langsung di DB: sesi lama sah dikeluarkan AuthenticateSession, jadi mulai sesi baru.
    $this->user->update(['password' => 'kopi pagi di gudang']);
    $this->flushSession();
    $this->actingAs($this->user)
        ->put('/ganti-password', ['password' => 'kopi pagi di gudang', 'password_confirmation' => 'kopi pagi di gudang'])
        ->assertSessionHasErrors(['password' => 'Password baru tidak boleh sama dengan password saat ini.']);

    expect($this->user->refresh()->must_change_password)->toBeTrue();
});
