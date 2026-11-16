<?php

use App\Domain\Access\Enums\DataScope;
use App\Domain\Access\Models\Role;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Device\Notifications\DevicePending;
use App\Domain\Notification\Otp\OtpChannel;
use App\Domain\Notification\Otp\OtpManager;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;

/*
 * Cakupan BR-060–BR-070 per kriteria (keputusan Mas 16 Nov 2026, matriks di 10 "Cakupan
 * BR-060–BR-070"). Berkas ini hanya menambal kriteria yang belum teruji di test lain; kriteria yang
 * sudah teruji tidak diulang. Waktu acuan Senin 16 Nov 2026 (WIB), shift PAGI 08:00–16:00, buka
 * absen 120 menit sebelum, tutup pulang 360 menit sesudah. Data fiktif.
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->useRealPermissions();
    $this->w = AttendanceWorld::make($this);
    $this->phone = $this->w->phone();
    $this->w->s->weekdays($this->phone['employee'], '2026-01-01');
});

afterEach(fn () => Carbon::setTestNow());

function brAt(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Asia/Jakarta')->utc());
}

test('BR-060 jendela masuk berakhir di jam selesai shift: setelah 16.00 ditolak OUTSIDE_WINDOW, tepat 16.00 diterima', function () {
    brAt('2026-11-16 16:01:00');
    AttendanceWorld::post($this, $this->phone)->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUTSIDE_WINDOW');

    $other = $this->w->phone();
    $this->w->s->weekdays($other['employee'], '2026-01-01');
    brAt('2026-11-16 16:00:00');
    AttendanceWorld::post($this, $other)->assertCreated()->assertJsonPath('data.work_date', '2026-11-16');
});

test('BR-060 jendela pulang berakhir di end + checkout_close_min: 22.00 diterima, 22.01 ditolak OUTSIDE_WINDOW', function () {
    brAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated();

    brAt('2026-11-16 22:01:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUTSIDE_WINDOW');

    brAt('2026-11-16 22:00:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertCreated()
        ->assertJsonPath('data.check_out_at', '2026-11-16T22:00:00+07:00');
});

test('BR-063 shift kemarin yang TIDAK lintas tengah malam bukan kandidat: pulang 00.30 → OUTSIDE_WINDOW, hari kemarin tidak berubah', function () {
    brAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated();

    brAt('2026-11-17 00:30:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUTSIDE_WINDOW');

    expect(AttendanceDay::query()->sole()->check_out_at)->toBeNull()
        ->and(AttendanceLog::query()->count())->toBe(1);
});

test('BR-064 lokasi tanpa radius sendiri memakai attendance.default_radius_m (+ akurasi)', function () {
    $this->w->s->org->gudang->forceFill(['attendance_radius_m' => null])->save();
    setSetting('attendance.default_radius_m', 200);
    brAt('2026-11-16 08:00:00');

    // 200 m + akurasi 10 m = 210 m.
    [$lat, $lng] = AttendanceWorld::north(AttendanceWorld::GUDANG, 220);
    AttendanceWorld::post($this, $this->phone, ['latitude' => (string) $lat, 'longitude' => (string) $lng])
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');

    [$lat, $lng] = AttendanceWorld::north(AttendanceWorld::GUDANG, 205);
    AttendanceWorld::post($this, $this->phone, ['latitude' => (string) $lat, 'longitude' => (string) $lng])->assertCreated();
});

test('BR-066 toleransi pulang cepat attendance.early_leave_tolerance_min: dalam toleransi = 0, lewat = menit dari jam selesai', function () {
    setSetting('attendance.early_leave_tolerance_min', 10);
    brAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated();

    brAt('2026-11-16 15:50:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertCreated()
        ->assertJsonPath('data.status', 'present');
    expect(AttendanceDay::query()->sole()->early_leave_minutes)->toBe(0);

    $other = $this->w->phone();
    $this->w->s->weekdays($other['employee'], '2026-01-01');
    brAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $other)->assertCreated();
    brAt('2026-11-16 15:49:00');
    AttendanceWorld::post($this, $other, ['type' => 'out'])->assertCreated()->assertJsonPath('data.status', 'early_leave');
    expect(AttendanceDay::query()->where('employee_id', $other['employee']->id)->sole()->early_leave_minutes)->toBe(11);
});

test('BR-067 absen online dengan jam HP meleset: waktu tetap waktu server (client_time diabaikan), hanya penanda anomali', function () {
    brAt('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, [
        'client_time' => '2026-11-16T07:30:00+07:00',
        'device_clock_offset_s' => '-1800',
    ])->assertCreated()->assertJsonPath('data.check_in_at', '2026-11-16T08:00:00+07:00');

    $log = AttendanceLog::query()->sole();
    expect($log->recorded_at->toIso8601String())->toBe('2026-11-16T01:00:00+00:00')
        ->and($log->flags)->toBe(['clock_skew_s' => 1800])
        ->and(AttendanceDay::query()->sole()->has_anomaly)->toBeTrue();
});

test('BR-067 absen offline tetap divalidasi radius & lokasi palsu (tidak ada log tersimpan)', function () {
    brAt('2026-11-16 09:00:00');
    [$lat, $lng] = AttendanceWorld::north(AttendanceWorld::GUDANG, 500);

    AttendanceWorld::post($this, $this->phone, ['is_offline' => '1', 'client_time' => '2026-11-16T08:00:00+07:00', 'latitude' => (string) $lat, 'longitude' => (string) $lng])
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUT_OF_RADIUS');
    AttendanceWorld::post($this, $this->phone, ['is_offline' => '1', 'client_time' => '2026-11-16T08:00:00+07:00', 'is_mock' => '1'])
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_MOCK_LOCATION');

    expect(AttendanceLog::query()->count())->toBe(0);
});

test('BR-070 perangkat pending: hanya pemegang devices.manage yang scope-nya mencakup pemilik yang diberi tahu', function () {
    $role = Role::query()->create(['name' => 'perangkat_lokasi', 'label' => 'Perangkat lokasi', 'guard_name' => 'web', 'data_scope' => DataScope::Location]);
    $role->givePermissionTo('devices.manage');
    $local = User::factory()->withTwoFactor()->create(['employee_id' => $this->w->s->org->employee($this->w->s->org->gudang)->id])->assignRole('perangkat_lokasi');
    $elsewhere = User::factory()->withTwoFactor()->create(['employee_id' => $this->w->s->org->employee($this->w->s->org->toko)->id])->assignRole('perangkat_lokasi');
    setSetting('security.password_check_breached', false);
    // Pegawai tanpa MFA (role `pegawai` tidak mewajibkannya) agar login langsung sampai cek perangkat.
    $this->phone['user']->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    // Pemilik sudah punya HP aktif (AttendanceWorld); login dari HP kedua → pending.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/login', [
        'login' => $this->phone['user']->username,
        'password' => 'password',
        'device' => ['uid' => hash('sha256', 'hp-kedua'), 'model' => 'Uji Android 2', 'os_version' => 'Android 13'],
    ], ['Accept' => 'application/json', 'X-App-Version' => '1.0.0'])
        ->assertStatus(403)->assertJsonPath('error.code', 'DEVICE_PENDING');

    Notification::assertSentTo($local, DevicePending::class);
    Notification::assertNotSentTo([$elsewhere, $this->phone['user']], DevicePending::class);
});

test('BR-068 jam tutup hari mengikuti attendance.daily_close_time: tanpa absen → absent setelah jam tutup, belum ada baris sebelumnya', function () {
    setSetting('attendance.daily_close_time', '18:00');

    brAt('2026-11-16 17:30:00');
    $this->artisan('attendance:resolve-day', ['date' => '2026-11-16'])->assertSuccessful();
    expect(AttendanceDay::query()->where('employee_id', $this->phone['employee']->id)->exists())->toBeFalse();

    brAt('2026-11-16 18:30:00');
    $this->artisan('attendance:resolve-day', ['date' => '2026-11-16'])->assertSuccessful();
    expect(AttendanceDay::query()->where('employee_id', $this->phone['employee']->id)->sole()->status->value)->toBe('absent');
});

test('BR-070 kuota OTP lupa password terpisah: batas WhatsApp notifikasi 0 tidak menghalangi kode OTP lewat WhatsApp', function () {
    setSetting('notification.whatsapp_daily_limit', 0);
    setSetting('security.password_check_breached', false);
    setSetting('auth.forgot_channels', ['email', 'whatsapp']);
    config(['otp.channels.whatsapp.driver' => 'log']);
    $otp = new class implements OtpChannel
    {
        /** @var list<string> */
        public array $sent = [];

        public function send(string $address, string $message): void
        {
            $this->sent[] = $address;
        }
    };
    app(OtpManager::class)->fake($otp);
    User::factory()->create(['username' => 'otp.uji', 'phone' => '6281200000001']);

    $this->from('/forgot-password')->post('/forgot-password', ['login' => 'otp.uji', 'channel' => 'whatsapp'])
        ->assertRedirect('/reset-password-otp');

    expect($otp->sent)->toBe(['6281200000001']);
});
