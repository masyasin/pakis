<?php

use App\Domain\Access\Actions\ResetMfa;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;

/*
 * FR-008, BR-015, SEC-007: MFA wajib per role, kode pemulihan sekali tampil & sekali pakai.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
});

function mfaUser(string $role, bool $withMfa = false, array $attributes = []): User
{
    $factory = User::factory();
    if ($withMfa) {
        // Secret Base32 asli (factory memakai 'secret' palsu yang tidak bisa diverifikasi TOTP).
        $factory = $factory->withTwoFactor()->state(['two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey())]);
    }

    return $factory->create($attributes)->assignRole($role);
}

function confirmedPassword()
{
    return test()->withSession(['auth.password_confirmed_at' => time()]);
}

test('BR-015 Owner tanpa MFA dialihkan ke aktivasi dari halaman mana pun', function (string $uri) {
    $this->actingAs(mfaUser('owner'))->get($uri)->assertRedirect('/aktivasi-mfa');
})->with(['/dasbor', '/role', '/profil', '/pengaturan']);

test('BR-015 Owner dengan MFA aktif & role tanpa wajib MFA tidak dipaksa', function () {
    $this->actingAs(mfaUser('owner', withMfa: true))->get('/dasbor')->assertOk();
    $this->actingAs(mfaUser('supervisor'))->get('/dasbor')->assertOk();
});

test('BR-015 halaman aktivasi tampil (butuh konfirmasi password); yang sudah aktif diarahkan ke dasbor', function () {
    $this->actingAs(mfaUser('hrd'));
    $this->get('/aktivasi-mfa')->assertRedirect(route('password.confirm'));
    confirmedPassword()->get('/aktivasi-mfa')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/mfa-setup-required'));

    $this->actingAs(mfaUser('hrd', withMfa: true));
    confirmedPassword()->get('/aktivasi-mfa')->assertRedirect('/dasbor');
});

test('BR-015 endpoint aktivasi Fortify tetap terbuka selama MFA belum aktif', function () {
    $this->actingAs(mfaUser('owner'));

    confirmedPassword()->post('/user/two-factor-authentication')->assertRedirect();
    confirmedPassword()->getJson('/user/two-factor-qr-code')->assertOk();
});

test('wajib ganti password diproses sebelum wajib MFA', function () {
    $this->actingAs(mfaUser('owner', attributes: ['must_change_password' => true]))
        ->get('/dasbor')->assertRedirect(route('admin.password.force'));
});

test('BR-015 aktivasi lengkap: confirm → mfa_enabled, lalu dasbor terbuka', function () {
    $user = mfaUser('owner');
    $this->actingAs($user);

    confirmedPassword()->post('/user/two-factor-authentication');
    $secret = decrypt($user->refresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    confirmedPassword()->post('/user/confirmed-two-factor-authentication', ['code' => $code])->assertSessionHasNoErrors();

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'mfa_enabled')->where('user_id', $user->id)->exists())->toBeTrue();
    $this->get('/dasbor')->assertOk();
});

test('BR-015 MFA tidak bisa dinonaktifkan selama role mewajibkan; role lain bisa (mfa_disabled)', function () {
    $owner = mfaUser('owner', withMfa: true);
    $this->actingAs($owner);
    confirmedPassword()->delete('/user/two-factor-authentication')->assertForbidden();
    expect($owner->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $supervisor = mfaUser('supervisor', withMfa: true);
    $this->actingAs($supervisor);
    confirmedPassword()->delete('/user/two-factor-authentication')->assertRedirect();

    expect($supervisor->refresh()->two_factor_secret)->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'mfa_disabled')->where('user_id', $supervisor->id)->exists())->toBeTrue();
});

test('SEC-007 kode pemulihan tampil sekali setelah dibuat; buat ulang → tampil sekali lagi', function () {
    $user = mfaUser('owner');
    $this->actingAs($user);

    confirmedPassword()->post('/user/two-factor-authentication');
    confirmedPassword()->getJson('/user/two-factor-recovery-codes')->assertOk()->assertJsonCount(8);
    confirmedPassword()->getJson('/user/two-factor-recovery-codes')->assertNotFound();

    confirmedPassword()->post('/user/two-factor-recovery-codes')->assertRedirect();
    confirmedPassword()->getJson('/user/two-factor-recovery-codes')->assertOk()->assertJsonCount(8);
    confirmedPassword()->getJson('/user/two-factor-recovery-codes')->assertNotFound();
});

test('SEC-007 kode pemulihan sekali pakai saat login', function () {
    mfaUser('owner', withMfa: true, attributes: ['username' => 'pemilik.usaha']);

    $login = function () {
        // Keluar total (guard + sesi) agar request berikutnya benar-benar tamu.
        if (auth()->guard('web')->check()) {
            auth()->guard('web')->logout();
        }
        app('auth')->forgetGuards();
        test()->flushSession();
        test()->post('/login', ['login' => 'pemilik.usaha', 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    };

    $login();
    $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $login();
    $this->post('/two-factor-challenge', ['recovery_code' => 'recovery-code-1'])->assertSessionHasErrors();
});

test('SEC-007 kode challenge salah → mfa_failed; rate limit 5 per menit', function () {
    $user = mfaUser('owner', withMfa: true, attributes: ['username' => 'pemilik.usaha']);
    $this->post('/login', ['login' => 'pemilik.usaha', 'password' => 'password'])->assertRedirect('/two-factor-challenge');

    foreach (range(1, 5) as $i) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }
    $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);

    expect(SecurityEvent::query()->where('event', 'mfa_failed')->where('user_id', $user->id)->count())->toBeGreaterThanOrEqual(1);
});

test('BR-015 ResetMfa: kosongkan MFA, cabut sesi, catat event & audit', function () {
    $hrd = mfaUser('hrd', withMfa: true);
    $owner = mfaUser('owner', withMfa: true);
    DB::table('sessions')->insert(['id' => str_repeat('s', 40), 'user_id' => $hrd->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($owner);
    app(ResetMfa::class)->handle($owner, $hrd);

    $hrd->refresh();
    expect($hrd->two_factor_secret)->toBeNull()
        ->and($hrd->two_factor_recovery_codes)->toBeNull()
        ->and($hrd->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $hrd->id)->exists())->toBeFalse()
        ->and(SecurityEvent::query()->where('event', 'mfa_reset_by_admin')->sole()->user_id)->toBe($hrd->id)
        ->and(AuditLog::query()->where('event', 'user.mfa_reset')->exists())->toBeTrue();
});

test('BR-015 ResetMfa ditolak: diri sendiri, Super Admin → Owner, admin → Owner', function (string $actorRole, bool $self) {
    $owner = mfaUser('owner', withMfa: true);
    $actor = $self ? $owner : mfaUser($actorRole, withMfa: true);
    $this->actingAs($actor);

    expect(fn () => app(ResetMfa::class)->handle($actor, $owner))->toThrow(AuthorizationException::class);
    expect($owner->refresh()->two_factor_confirmed_at)->not->toBeNull();
})->with([
    'diri sendiri' => ['owner', true],
    'super admin ke owner' => ['super_admin', false],
    'administrator ke owner' => ['administrator', false],
]);

test('BR-015 login pengguna wajib aktivasi dihitung sebagai konfirmasi password (tidak diminta dua kali)', function () {
    mfaUser('owner', attributes: ['username' => 'pemilik.baru']);
    mfaUser('supervisor', attributes: ['username' => 'spv.biasa']);

    $this->post('/login', ['login' => 'pemilik.baru', 'password' => 'password']);
    $this->get('/aktivasi-mfa')->assertOk();

    app('auth')->guard('web')->logout();
    app('auth')->forgetGuards();
    $this->flushSession();

    // Pengguna yang tidak wajib aktivasi tetap mengonfirmasi ulang sebelum halaman sensitif.
    $this->post('/login', ['login' => 'spv.biasa', 'password' => 'password']);
    $this->get('/profil/keamanan')->assertRedirect(route('password.confirm'));
});
