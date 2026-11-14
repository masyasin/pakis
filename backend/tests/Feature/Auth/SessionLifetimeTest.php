<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * BR-018 / SEC-005: idle & absolute timeout dari setting, regenerasi sesi, akun aktif (EnsureUserActive),
 * dan gerbang menu web (BR-017) untuk sesi yang sedang berjalan.
 */

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'siti.aminah']);
});

/**
 * Di test, aplikasi (dan guard yang menyimpan objek user) dipakai ulang antar request. Di produksi
 * setiap request memuat user dari DB, jadi lupakan guard untuk meniru request baru.
 */
function newRequest(): void
{
    app('auth')->forgetGuards();
}

function loginAs(string $login = 'siti.aminah'): void
{
    test()->post(route('login.store'), ['login' => $login, 'password' => 'password'])
        ->assertRedirect(route('admin.dashboard', absolute: false));
}

test('SEC-005 ID sesi berganti saat login dan tidak ada cookie "ingat saya"', function () {
    $this->get('/login');
    $before = session()->getId();

    $response = $this->post(route('login.store'), ['login' => 'siti.aminah', 'password' => 'password', 'remember' => '1']);

    expect(session()->getId())->not->toBe($before);
    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getName())->not->toStartWith('remember_');
    }
});

test('BR-018 tidak aktif melewati session_idle_minutes → keluar otomatis', function () {
    loginAs();

    $this->travel(119)->minutes();
    $this->get('/dasbor')->assertOk();

    $this->travel(121)->minutes();
    $this->get('/dasbor')->assertRedirect('/login');
    $this->assertGuest();
    expect(session('status'))->toBe('Sesi Anda berakhir karena tidak aktif. Silakan masuk lagi.');
});

test('BR-018 batas idle mengikuti setting', function () {
    setSetting('security.session_idle_minutes', 30);
    loginAs();

    $this->travel(31)->minutes();
    $this->get('/dasbor')->assertRedirect('/login');
});

test('BR-018 walau aktif terus, sesi berakhir setelah session_absolute_hours', function () {
    loginAs();

    foreach (range(1, 11) as $hour) {
        $this->travel(60)->minutes();
        $this->get('/dasbor')->assertOk();
    }

    $this->travel(61)->minutes();
    $this->get('/dasbor')->assertRedirect('/login');
    $this->assertGuest();
    expect(session('status'))->toBe('Sesi Anda sudah terlalu lama. Silakan masuk lagi.');
});

test('akun yang dinonaktifkan di tengah sesi langsung keluar', function () {
    loginAs();

    $this->user->update(['is_active' => false]);
    newRequest();

    $this->get('/dasbor')->assertRedirect('/login');
    $this->assertGuest();
});

test('akun yang dihapus atau dikunci di tengah sesi langsung keluar', function (string $change) {
    loginAs();

    match ($change) {
        'dihapus' => $this->user->delete(),
        'dikunci' => $this->user->update(['locked_until' => now()->addMinutes(15)]),
    };
    newRequest();

    $this->get('/dasbor')->assertRedirect('/login');
    $this->assertGuest();
})->with(['dihapus', 'dikunci']);

test('BR-017 sesi berjalan yang kehilangan semua permission menu → keluar dengan pesan Pakis Pro', function () {
    loginAs();

    Gate::define('dashboard.view', fn () => false);

    $this->get('/dasbor')->assertRedirect('/login');
    $this->assertGuest();
    expect(session('status'))->toBe('Akun Anda hanya untuk aplikasi Pakis Pro.');
});
