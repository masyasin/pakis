<?php

use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Device\Enums\DeviceStatus;
use App\Domain\Device\Models\Device;
use App\Domain\Device\Notifications\DevicePending;
use App\Domain\Employee\Models\Employee;
use App\Domain\Notification\Otp\OtpChannel;
use App\Domain\Notification\Otp\OtpManager;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

/*
 * API autentikasi Pakis Pro (T-031): 05 §2.3, §5.1–§5.3; FR-002/003/004/005/006; BR-016, BR-027,
 * BR-040; SEC-001, SEC-006, SEC-020, SEC-068.
 */

beforeEach(function () {
    $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    $this->useRealPermissions();
    Notification::fake();
    setSetting('security.password_check_breached', false);

    $this->pegawai = User::factory()->create(['username' => 'agus.s', 'password' => Hash::make('kopi pagi di gudang')])->assignRole('pegawai');
});

/** device_uid uji: hash SHA-256 seperti klien (bukan ANDROID_ID mentah). */
function phoneUid(string $seed): string
{
    return hash('sha256', 'hp-'.$seed);
}

/** @return array<string, string> */
function mobileHeaders(?string $uid = null, string $version = '1.0.0', ?string $token = null): array
{
    return array_filter([
        'Accept' => 'application/json',
        'X-App-Version' => $version,
        'X-Device-Id' => $uid,
        'Authorization' => $token === null ? null : 'Bearer '.$token,
    ]);
}

/** @param array<string, mixed> $extra */
function apiLogin(string $login, string $password, string $uid, array $extra = [], string $version = '1.0.0'): TestResponse
{
    return test()->postJson('/api/v1/auth/login', [
        'login' => $login,
        'password' => $password,
        'device' => ['uid' => $uid, 'model' => 'Samsung SM-A045F', 'os_version' => 'Android 13'],
        ...$extra,
    ], mobileHeaders(version: $version));
}

/** Login sukses → [token, uid]. Guard di-reset agar request berikutnya benar-benar memakai token. */
function loggedInPhone(string $seed = 'a', string $login = 'agus.s', string $password = 'kopi pagi di gudang'): array
{
    $uid = phoneUid($seed);
    $token = (string) apiLogin($login, $password, $uid)->assertOk()->json('data.token');
    app('auth')->forgetGuards();

    return [$token, $uid];
}

function apiGet(string $uri, string $token, string $uid, string $version = '1.0.0'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson($uri, mobileHeaders($uid, $version, $token));
}

test('FR-002 BR-040 login HP pertama: perangkat langsung aktif, token terikat perangkat & berisi data 05 §5.3', function () {
    $employee = Employee::factory()->create();
    $this->pegawai->forceFill(['employee_id' => $employee->id])->save();

    $response = apiLogin('AGUS.S', 'kopi pagi di gudang', phoneUid('a'))->assertOk();

    $response->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.device.status', 'active')
        ->assertJsonPath('data.user.username', 'agus.s')
        ->assertJsonPath('data.user.must_change_password', false)
        ->assertJsonPath('data.user.employee.employee_number', $employee->employee_number)
        ->assertJsonPath('data.user.permissions', ['attendance.self', 'attendance_corrections.create'])
        ->assertJsonStructure(['meta' => ['request_id']]);

    $device = Device::query()->sole();
    $token = PersonalAccessToken::query()->sole();
    expect($device->status)->toBe(DeviceStatus::Active)
        ->and($device->token_id)->toBe($token->id)
        ->and($device->app_version)->toBe('1.0.0')
        ->and($token->name)->toBe("device:{$device->id}")
        ->and($token->abilities)->toBe(['attendance.self', 'attendance_corrections.create'])
        ->and($token->expires_at?->diffInDays(now()->addDays(90)))->toBeLessThan(1.0)
        ->and(SecurityEvent::query()->where('event', 'device_registered')->exists())->toBeTrue();
});

test('SEC-001 INVALID_CREDENTIALS seragam: akun tidak ada, password salah, akun nonaktif, pegawai nonaktif', function () {
    $inactive = User::factory()->create(['username' => 'nonaktif', 'is_active' => false, 'password' => Hash::make('kopi pagi di gudang')])->assignRole('pegawai');
    $leaver = User::factory()->create(['username' => 'keluar', 'employee_id' => Employee::factory()->inactive()->create()->id, 'password' => Hash::make('kopi pagi di gudang')])->assignRole('pegawai');

    $bodies = collect([
        apiLogin('tidak.ada', 'kopi pagi di gudang', phoneUid('a')),
        apiLogin('agus.s', 'salah sekali', phoneUid('a')),
        apiLogin($inactive->username, 'kopi pagi di gudang', phoneUid('a')),
        apiLogin($leaver->username, 'kopi pagi di gudang', phoneUid('a')),
    ])->map(function (TestResponse $r) {
        $r->assertStatus(401)->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        return [$r->json('error.message'), $r->json('error.details')];
    });

    expect($bodies->unique()->count())->toBe(1)
        ->and(Device::query()->count())->toBe(0)
        ->and(SecurityEvent::query()->where('event', 'login_failed')->where('channel', 'api')->count())->toBe(4);
});

test('BR-016 terlalu banyak percobaan → 423 ACCOUNT_LOCKED dengan retry_after_s', function () {
    setSetting('security.login_max_attempts', 3);

    foreach (range(1, 3) as $_) {
        apiLogin('agus.s', 'salah sekali', phoneUid('a'))->assertStatus(401);
    }

    apiLogin('agus.s', 'kopi pagi di gudang', phoneUid('a'))
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'ACCOUNT_LOCKED')
        ->assertJsonPath('error.details.retry_after_s', fn ($s) => is_int($s) && $s > 0);
});

test('05 §2.3 akun tanpa permission mobile → 403 FORBIDDEN, tidak membuat perangkat', function () {
    User::factory()->create(['username' => 'tanpa.role', 'password' => Hash::make('kopi pagi di gudang')]);

    apiLogin('tanpa.role', 'kopi pagi di gudang', phoneUid('a'))
        ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    expect(Device::query()->count())->toBe(0);
});

test('BR-015 MFA di API: wajib tapi belum aktif, kode kosong, kode salah, TOTP & kode pemulihan', function () {
    $hrd = User::factory()->create(['username' => 'hrd.uji', 'password' => Hash::make('kopi pagi di gudang')])->assignRole('hrd');

    apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'))
        ->assertStatus(403)->assertJsonPath('error.code', 'MFA_SETUP_REQUIRED');

    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $hrd->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['pulih-satu-111', 'pulih-dua-222'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'))
        ->assertStatus(401)->assertJsonPath('error.code', 'MFA_REQUIRED')->assertJsonPath('error.details.mfa', true);
    apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'), ['otp' => '000000'])
        ->assertStatus(401)->assertJsonPath('error.code', 'MFA_INVALID');
    expect(Device::query()->count())->toBe(0);

    apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'), ['otp' => $google->getCurrentOtp($secret)])->assertOk();

    app('auth')->forgetGuards();
    apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'), ['otp' => 'pulih-satu-111'])->assertOk();
    expect($hrd->fresh()->recoveryCodes())->not->toContain('pulih-satu-111');
});

test('BR-040 HP kedua → 403 DEVICE_PENDING tanpa token, admin diberi tahu sekali; disetujui → HP lama diganti & tokennya mati', function () {
    $hrd = User::factory()->withTwoFactor()->create()->assignRole('hrd');
    [$oldToken, $oldUid] = loggedInPhone('a');

    apiLogin('agus.s', 'kopi pagi di gudang', phoneUid('b'))
        ->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_PENDING')->assertJsonMissingPath('data');
    // Login ulang dari HP yang sama tidak menggandakan permintaan & notifikasi.
    apiLogin('agus.s', 'kopi pagi di gudang', phoneUid('b'))->assertStatus(403);

    $pending = Device::query()->where('status', 'pending')->sole();
    expect(PersonalAccessToken::query()->count())->toBe(1);
    Notification::assertSentTo($hrd, DevicePending::class);
    Notification::assertSentToTimes($hrd, DevicePending::class, 1);

    $this->actingAs($hrd)->post("/perangkat/{$pending->id}/setujui")->assertRedirect();
    auth()->logout();

    expect($pending->fresh()->status)->toBe(DeviceStatus::Active)
        ->and(Device::query()->where('device_uid', $oldUid)->value('status'))->toBe(DeviceStatus::Replaced);
    apiGet('/api/v1/me', $oldToken, $oldUid)->assertStatus(401);

    // HP baru masuk ulang → dapat token.
    [$newToken, $newUid] = loggedInPhone('b');
    apiGet('/api/v1/me', $newToken, $newUid)->assertOk()->assertJsonPath('data.username', 'agus.s');
});

test('SEC-020 token dipakai dengan X-Device-Id lain → 401 DEVICE_MISMATCH dan token dicabut', function () {
    [$token, $uid] = loggedInPhone('a');

    apiGet('/api/v1/me', $token, phoneUid('lain'))
        ->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_MISMATCH');

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(SecurityEvent::query()->where('event', 'token_revoked')->exists())->toBeTrue();
    // Token sudah mati walau kembali ke HP asli.
    apiGet('/api/v1/me', $token, $uid)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('SEC-020 token tanpa X-Device-Id atau milik perangkat yang dicabut → 401 DEVICE_MISMATCH', function () {
    [$token, $uid] = loggedInPhone('a');
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me', mobileHeaders(token: $token))->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_MISMATCH');

    [$token, $uid] = loggedInPhone('a');
    Device::query()->update(['status' => 'revoked']);
    apiGet('/api/v1/me', $token, $uid)->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_MISMATCH');
});

test('BR-040 satu HP untuk dua akun aktif ditolak: auto-approve dilewati, setujui ditolak, index unik DB', function () {
    $hrd = User::factory()->withTwoFactor()->create()->assignRole('hrd');
    User::factory()->create(['username' => 'budi', 'password' => Hash::make('kopi pagi di gudang')])->assignRole('pegawai');
    loggedInPhone('a');

    // HP yang sama dipakai akun lain → bukan auto-approve meski budi belum punya perangkat.
    apiLogin('budi', 'kopi pagi di gudang', phoneUid('a'))->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_PENDING');
    $pending = Device::query()->where('status', 'pending')->sole();

    $this->actingAs($hrd)->from('/perangkat')->post("/perangkat/{$pending->id}/setujui")
        ->assertSessionHasErrors('device');
    expect($pending->fresh()->status)->toBe(DeviceStatus::Pending);

    expect(fn () => DB::table('devices')->where('id', $pending->id)->update(['status' => 'active']))
        ->toThrow(QueryException::class);
});

test('BR-027 versi app < minimum → 426 di semua endpoint kecuali /config', function () {
    setSetting('mobile.min_version', '1.2.0');
    setSetting('mobile.download_url', 'https://pakis.example/unduh/pakis-pro.apk');

    apiLogin('agus.s', 'kopi pagi di gudang', phoneUid('a'), version: '1.1.9')
        ->assertStatus(426)
        ->assertJsonPath('error.code', 'APP_UPDATE_REQUIRED')
        ->assertJsonPath('error.details.download_url', 'https://pakis.example/unduh/pakis-pro.apk');
    $this->postJson('/api/v1/auth/password/forgot', ['login' => 'agus.s', 'channel' => 'email'], ['Accept' => 'application/json'])
        ->assertStatus(426);

    $this->getJson('/api/v1/config')->assertOk()->assertJsonPath('data.mobile.min_version', '1.2.0');

    [$token, $uid] = (function () {
        $uid = phoneUid('a');
        $token = (string) apiLogin('agus.s', 'kopi pagi di gudang', $uid, version: '1.2.0')->assertOk()->json('data.token');

        return [$token, $uid];
    })();
    apiGet('/api/v1/me', $token, $uid, '1.10.0')->assertOk();
    apiGet('/api/v1/me', $token, $uid, '1.1.0')->assertStatus(426);
});

test('SEC-006 wajib ganti password: hanya me, ganti password, dan keluar yang boleh', function () {
    $this->pegawai->forceFill(['must_change_password' => true])->save();
    [$token, $uid] = loggedInPhone('a');

    apiGet('/api/v1/me', $token, $uid)->assertOk()->assertJsonPath('data.must_change_password', true);
    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me', ['phone' => null], mobileHeaders($uid, token: $token))
        ->assertStatus(403)->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');

    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me/password', [
        'current_password' => 'kopi pagi di gudang', 'password' => 'teh sore di kantor', 'password_confirmation' => 'teh sore di kantor',
    ], mobileHeaders($uid, token: $token))->assertOk()->assertJsonPath('data.must_change_password', false);

    // Token HP yang dipakai mengganti tetap hidup.
    apiGet('/api/v1/me', $token, $uid)->assertOk();
});

test('BR-040 ganti password dari HP mencabut token lain akun itu, bukan token HP ini', function () {
    [$token, $uid] = loggedInPhone('a');
    $other = $this->pegawai->createToken('device:999')->plainTextToken;

    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me/password', [
        'current_password' => 'kopi pagi di gudang', 'password' => 'teh sore di kantor', 'password_confirmation' => 'teh sore di kantor',
    ], mobileHeaders($uid, token: $token))->assertOk();

    expect(PersonalAccessToken::query()->pluck('name')->all())->toBe(['device:'.Device::query()->value('id')]);
    apiGet('/api/v1/me', $other, $uid)->assertStatus(401);
});

test('SEC-020 token kedaluwarsa (mobile.token_days) → 401', function () {
    setSetting('mobile.token_days', 7);
    [$token, $uid] = loggedInPhone('a');

    $this->travel(8)->days();

    apiGet('/api/v1/me', $token, $uid)->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

test('SEC-021 akun dinonaktifkan setelah login → request API 401 dan token dicabut', function () {
    [$token, $uid] = loggedInPhone('a');
    $this->pegawai->forceFill(['is_active' => false])->save();

    apiGet('/api/v1/me', $token, $uid)->assertStatus(401);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('SEC-068 API umum dibatasi 120 request/menit per token', function () {
    [$token, $uid] = loggedInPhone('a');

    foreach (range(1, 120) as $_) {
        apiGet('/api/v1/me', $token, $uid)->assertOk();
    }

    apiGet('/api/v1/me', $token, $uid)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

test('FR-010 ubah nomor HP lewat API wajib password saat ini', function () {
    [$token, $uid] = loggedInPhone('a');

    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me', ['phone' => '0812 3456 7890'], mobileHeaders($uid, token: $token))
        ->assertStatus(422)->assertJsonPath('error.details.current_password.0', 'Masukkan password saat ini yang benar untuk mengganti nomor HP.');

    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me', ['phone' => '0812 3456 7890', 'current_password' => 'kopi pagi di gudang'], mobileHeaders($uid, token: $token))
        ->assertOk()->assertJsonPath('data.phone', '6281234567890'); // T-073: disimpan 62…
});

test('BR-166(1) PUT /me: HP lama 08… tanpa diubah tidak meminta password dan tersimpan 62…; HP tak sah ditolak', function () {
    $this->pegawai->forceFill(['phone' => '081234567890'])->save();
    [$token, $uid] = loggedInPhone('a');

    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me', ['phone' => '0812-3456-7890'], mobileHeaders($uid, token: $token))
        ->assertOk()->assertJsonPath('data.phone', '6281234567890');

    app('auth')->forgetGuards();
    $this->putJson('/api/v1/me', ['phone' => '+1 555 123 4567', 'current_password' => 'kopi pagi di gudang'], mobileHeaders($uid, token: $token))
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect($this->pegawai->refresh()->phone)->toBe('6281234567890');
});

test('05 §5.1 keluar mencabut token; cabut sesi web milik sendiri tidak menyentuh token HP', function () {
    [$token, $uid] = loggedInPhone('a');
    DB::table('sessions')->insert(['id' => 'sesi-web-1', 'user_id' => $this->pegawai->id, 'payload' => '', 'last_activity' => time()]);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/me/sessions/revoke-others', [], mobileHeaders($uid, token: $token))
        ->assertOk()->assertJsonPath('data.revoked', 1);
    expect(DB::table('sessions')->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/logout', [], mobileHeaders($uid, token: $token))->assertOk();
    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(Device::query()->value('token_id'))->toBeNull();
});

test('FR-005 FR-006 lupa password API: respons seragam; kode OTP salah ditolak; kode benar mengganti password & mencabut token', function () {
    config(['otp.channels.whatsapp.driver' => 'log']);
    setSetting('auth.forgot_channels', ['email', 'whatsapp']);
    $channel = new class implements OtpChannel
    {
        public ?string $code = null;

        public function send(string $address, string $message): void
        {
            $this->code = preg_match('/\b(\d{6,8})\b/', $message, $m) ? $m[1] : null;
        }
    };
    app(OtpManager::class)->fake($channel);
    $this->pegawai->forceFill(['phone' => '6281234567890'])->save();
    [$token, $uid] = loggedInPhone('a');

    $known = $this->postJson('/api/v1/auth/password/forgot', ['login' => 'agus.s', 'channel' => 'whatsapp'], mobileHeaders())->assertOk();
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['login' => 'tidak.ada', 'channel' => 'whatsapp'], mobileHeaders())->assertOk();
    expect($known->json('data'))->toBe($unknown->json('data'))->and($channel->code)->not->toBeNull();

    $this->postJson('/api/v1/auth/password/reset-otp', [
        'login' => 'agus.s', 'code' => '000000', 'password' => 'teh sore di kantor', 'password_confirmation' => 'teh sore di kantor',
    ], mobileHeaders())->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    $this->postJson('/api/v1/auth/password/reset-otp', [
        'login' => 'agus.s', 'code' => $channel->code, 'password' => 'teh sore di kantor', 'password_confirmation' => 'teh sore di kantor',
    ], mobileHeaders())->assertOk();

    expect(Hash::check('teh sore di kantor', $this->pegawai->fresh()->password))->toBeTrue()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('BR-016 kunci karena salah password tidak memutus token HP yang sudah sah (cegah DoS lewat salah login)', function () {
    [$token, $uid] = loggedInPhone('a');
    $this->pegawai->forceFill(['locked_until' => now()->addMinutes(15)])->save();

    apiGet('/api/v1/me', $token, $uid)->assertOk();
    expect(PersonalAccessToken::query()->count())->toBe(1);
});

test('BR-015 tebakan MFA dibatasi 5/menit per akun, termasuk kode benar sesudahnya', function () {
    $hrd = User::factory()->create(['username' => 'hrd.uji', 'password' => Hash::make('kopi pagi di gudang')])->assignRole('hrd');
    $google = new Google2FA;
    $secret = $google->generateSecretKey();
    $hrd->forceFill(['two_factor_secret' => encrypt($secret), 'two_factor_confirmed_at' => now()])->save();

    foreach (range(1, 5) as $_) {
        apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'), ['otp' => '000000'])->assertJsonPath('error.code', 'MFA_INVALID');
    }

    apiLogin('hrd.uji', 'kopi pagi di gudang', phoneUid('h'), ['otp' => $google->getCurrentOtp($secret)])
        ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
