<?php

use App\Domain\Access\Notifications\AccountLocked;
use App\Domain\Access\Notifications\NewDeviceLogin;
use App\Domain\Access\Support\EmployeeStatus;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use App\Support\Http\ClientNetwork;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * FR-001, BR-010, BR-016, BR-017, SEC-001, SEC-003: login web username/email.
 */

const GENERIC_FAILURE = 'Username/email atau password salah.';

/** Pesan lockout per identifier+IP (BR-016); sama untuk akun yang ada maupun tidak. */
const THROTTLED = 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.';

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->create(['username' => 'siti.aminah', 'email' => 'siti@pakis-demo.test']);
});

function attemptLogin(string $login, string $password = 'password', string $ip = '10.0.0.5', string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeader('User-Agent', $agent)
        ->from('/login')
        ->post(route('login.store'), ['login' => $login, 'password' => $password]);
}

/** Pegawai terkait akun ini berstatus nonaktif (tabel pegawai baru ada di T-024). */
function employeeInactive(): void
{
    app()->instance(EmployeeStatus::class, new class implements EmployeeStatus
    {
        public function isActive(User $user): bool
        {
            return $user->employee_id === null;
        }
    });
}

// --- BR-010 -----------------------------------------------------------------------------------

test('BR-010 login dengan username atau email, huruf besar & spasi diabaikan', function (string $login) {
    attemptLogin($login)->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($this->user);
})->with(['siti.aminah', '  SITI.Aminah ', 'siti@pakis-demo.test', 'Siti@Pakis-Demo.TEST']);

test('BR-010 sukses mengisi last_login, mencatat login_success, dan mereset penghitung gagal', function () {
    attemptLogin('siti.aminah', 'salah');
    attemptLogin('siti.aminah');

    $this->user->refresh();
    expect($this->user->last_login_ip)->toBe('10.0.0.5')
        ->and($this->user->last_login_at)->not->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'login_success')->where('user_id', $this->user->id)->exists())->toBeTrue();
});

// --- SEC-001 ----------------------------------------------------------------------------------

test('SEC-001 empat kasus gagal → respons identik', function () {
    $inactive = User::factory()->create(['username' => 'nonaktif', 'is_active' => false]);
    $withEmployee = User::factory()->create(['username' => 'pegawai.keluar', 'employee_id' => 9001]);
    employeeInactive();

    $responses = [
        'tidak ada' => attemptLogin('tidak.ada.orangnya'),
        'password salah' => attemptLogin('siti.aminah', 'password-salah'),
        'akun nonaktif' => attemptLogin($inactive->username),
        'pegawai nonaktif' => attemptLogin($withEmployee->username),
    ];

    foreach ($responses as $response) {
        $response->assertStatus(302)
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['login' => GENERIC_FAILURE]);
        $this->assertGuest();
    }
});

test('SEC-001 hash dummy tetap diperiksa saat akun tidak ada (waktu respons setara)', function () {
    Hash::spy();

    attemptLogin('tidak.ada.orangnya', 'apa saja');

    Hash::shouldHaveReceived('check')->once();
});

test('SEC-001 akun terhapus (soft delete) diperlakukan sama dengan tidak ada', function () {
    $this->user->delete();

    attemptLogin('siti.aminah')->assertSessionHasErrors(['login' => GENERIC_FAILURE]);
    $this->assertGuest();
});

test('login_failed mencatat identifier yang dicoba, tanpa password', function () {
    attemptLogin('Siti.Aminah', 'Rahasia-Sekali-123');

    $event = SecurityEvent::query()->where('event', 'login_failed')->sole();
    expect($event->identifier)->toBe('siti.aminah')
        ->and(json_encode($event->context))->not->toContain('Rahasia-Sekali-123');
});

// --- BR-016 / SEC-003 -------------------------------------------------------------------------

test('BR-016 salah 5× → akun terkunci, event lockout, email ke pemilik', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('siti.aminah', "salah-{$i}");
    }

    expect($this->user->refresh()->locked_until)->not->toBeNull()
        ->and($this->user->locked_until->greaterThan(now()->addMinutes(14)))->toBeTrue()
        ->and(SecurityEvent::query()->where('event', 'lockout')->where('user_id', $this->user->id)->exists())->toBeTrue();

    Notification::assertSentTo($this->user, AccountLocked::class);
});

test('BR-016 setelah terkunci: IP sama dapat pesan throttle, IP lain pesan generik, lewat batas bisa masuk', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('siti.aminah', "salah-{$i}");
    }

    attemptLogin('siti.aminah')->assertSessionHasErrors(['login' => THROTTLED]);
    $this->assertGuest();

    attemptLogin('siti.aminah', ip: '172.16.9.9')->assertSessionHasErrors(['login' => GENERIC_FAILURE]);
    $this->assertGuest();

    $this->travel(16)->minutes();
    attemptLogin('siti.aminah', ip: '172.16.9.9')->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($this->user);
});

test('SEC-003 identifier yang tidak ada mendapat pesan throttle yang sama (tidak membocorkan akun)', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('tidak.ada', "salah-{$i}");
        attemptLogin('siti.aminah', "salah-{$i}", ip: '10.9.9.'.$i); // IP berbeda: tidak terkunci
    }

    attemptLogin('tidak.ada')->assertSessionHasErrors(['login' => THROTTLED]);

    User::factory()->create(['username' => 'budi']);
    foreach (range(1, 5) as $i) {
        attemptLogin('budi', "salah-{$i}", ip: '10.0.0.6');
    }
    attemptLogin('budi', ip: '10.0.0.6')->assertSessionHasErrors(['login' => THROTTLED]);

    expect($this->user->refresh()->locked_until)->toBeNull();
    Notification::assertNotSentTo($this->user, AccountLocked::class);
});

test('BR-016 variasi penulisan identifier untuk akun yang sama berbagi satu penghitung', function () {
    // Collation DB tidak membedakan aksen/huruf: semua variasi ini menemukan akun yang sama,
    // jadi tidak boleh masing-masing mendapat jatah percobaan baru.
    foreach (['siti.aminah', 'sití.aminah', 'SÍTI.AMINAH', 'siti@pakis-demo.test', 'sitì.aminah'] as $variant) {
        attemptLogin($variant, 'salah');
    }

    expect($this->user->refresh()->locked_until)->not->toBeNull();
    attemptLogin('siti.aminah')->assertSessionHasErrors(['login' => THROTTLED]);
});

test('BR-016 alamat IPv6 dalam satu blok /64 berbagi penghitung', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('siti.aminah', "salah-{$i}", ip: "2001:db8:abcd:12::{$i}");
    }

    attemptLogin('siti.aminah', ip: '2001:db8:abcd:12::ffff')->assertSessionHasErrors(['login' => THROTTLED]);
});

test('BR-016 batas per akun lintas IP: account_max_failures gagal dari IP mana pun → akun terkunci', function () {
    setSetting('security.account_max_failures', 10);

    foreach (range(1, 10) as $i) {
        attemptLogin('siti.aminah', 'salah', ip: "203.0.113.{$i}"); // tiap IP hanya 1 percobaan
    }

    expect($this->user->refresh()->locked_until)->not->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'lockout')->where('user_id', $this->user->id)->count())->toBe(1);
    Notification::assertSentTo($this->user, AccountLocked::class);

    // Password benar dari IP baru pun ditolak dengan pesan generik selama terkunci.
    attemptLogin('siti.aminah', ip: '198.51.100.7')->assertSessionHasErrors(['login' => GENERIC_FAILURE]);
});

test('SEC-003 varian huruf/aksen identifier yang tidak ada juga berbagi penghitung (tidak membedakan akun)', function () {
    foreach (['budi.tak.ada', 'BÚDI.tak.ada', 'búdi.TAK.ada', 'budí.tak.ada', 'Budi.Tak.Ada'] as $variant) {
        attemptLogin($variant, 'salah');
    }

    attemptLogin('budi.tak.ada')->assertSessionHasErrors(['login' => THROTTLED]);
});

test('BR-016 IPv4 dalam format IPv6 dihitung per alamat IPv4, bukan satu kelompok bersama', function () {
    foreach (range(1, 5) as $i) {
        attemptLogin('siti.aminah', "salah-{$i}", ip: '::ffff:192.0.2.10');
    }

    // Klien lain dengan alamat IPv4-mapped berbeda tidak ikut tertahan.
    expect(ClientNetwork::key('::ffff:192.0.2.10'))->toBe('192.0.2.10')
        ->and(ClientNetwork::key('::ffff:198.51.100.20'))->toBe('198.51.100.20')
        ->and(ClientNetwork::key('64:ff9b::c000:20a'))->toBe('192.0.2.10')
        ->and(ClientNetwork::key('2001:db8:abcd:12::1'))->toBe(ClientNetwork::key('2001:db8:abcd:12:ffff::9'));
});

test('email perangkat baru tidak meneruskan Markdown dari User-Agent (anti tautan palsu)', function () {
    $mail = (new NewDeviceLogin(now(), '10.0.0.5', 'Mozilla [Klik di sini](https://contoh-jahat.test) <b>x</b> `kode` *tebal*'))
        ->toMail($this->user);

    $text = implode("\n", $mail->introLines);
    expect($text)->not->toContain('](')
        ->not->toContain('<b>')
        ->not->toContain('`')
        ->toContain('Mozilla Klik di sini https://contoh-jahat.test');
});

test('BR-016 lebih dari 30 percobaan per menit dari satu IP → 429', function () {
    foreach (range(1, 30) as $i) {
        attemptLogin("orang{$i}", 'salah');
    }

    attemptLogin('orang31', 'salah')->assertTooManyRequests();
});

// --- BR-017 -----------------------------------------------------------------------------------

test('BR-017 akun tanpa permission menu web ditolak dengan pesan Pakis Pro', function () {
    Gate::define('dashboard.view', fn () => false);

    attemptLogin('siti.aminah')->assertSessionHasErrors(['login' => 'Akun Anda hanya untuk aplikasi Pakis Pro.']);
    $this->assertGuest();
    expect(SecurityEvent::query()->where('event', 'login_failed')->exists())->toBeFalse();
});

test('BR-017 pemegang salah satu permission menu boleh masuk', function () {
    Gate::define('dashboard.view', fn () => false);
    Gate::define('regions.view', fn () => true);

    attemptLogin('siti.aminah')->assertRedirect(route('dashboard', absolute: false));
});

// --- Perangkat baru (BR-018) ------------------------------------------------------------------

test('BR-018 login pertama dicatat tanpa email; IP /24 lain → new_device_login + email; /24 sama → tidak', function () {
    attemptLogin('siti.aminah', ip: '10.0.0.5');
    $this->post(route('logout'));

    expect(SecurityEvent::query()->where('event', 'new_device_login')->count())->toBe(1);
    Notification::assertNothingSent();

    attemptLogin('siti.aminah', ip: '10.0.0.77'); // /24 sama
    $this->post(route('logout'));
    expect(SecurityEvent::query()->where('event', 'new_device_login')->count())->toBe(1);

    attemptLogin('siti.aminah', ip: '192.168.5.10'); // /24 lain
    expect(SecurityEvent::query()->where('event', 'new_device_login')->count())->toBe(2);
    Notification::assertSentTo($this->user, NewDeviceLogin::class);
});

test('BR-018 setting notify_new_device_login mati → tetap dicatat, tanpa email', function () {
    setSetting('security.notify_new_device_login', false);
    attemptLogin('siti.aminah', ip: '10.0.0.5');
    $this->post(route('logout'));

    attemptLogin('siti.aminah', ip: '192.168.5.10');

    expect(SecurityEvent::query()->where('event', 'new_device_login')->count())->toBe(2);
    Notification::assertNothingSent();
});

test('logout tercatat di log keamanan', function () {
    attemptLogin('siti.aminah');
    $this->post(route('logout'));

    expect(SecurityEvent::query()->where('event', 'logout')->where('user_id', $this->user->id)->exists())->toBeTrue();
});
