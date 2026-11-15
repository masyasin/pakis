<?php

use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Notifications\AttendanceMockDetected;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AttendanceWorld;
use Tests\Support\ScheduleWorld;

/*
 * API absen inti (T-036): 05 §5.4, BR-060, BR-063, BR-065–BR-067, SEC-024, SEC-068.
 * Waktu acuan: Senin 16 Nov 2026 (WIB = UTC+7). Shift PAGI 08:00–16:00, buka absen 120 menit
 * sebelum, tutup pulang 360 menit sesudah, toleransi telat 0 kecuali disebut.
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

/** Waktu lokal WIB → setTestNow (UTC). */
function wib(string $local): void
{
    Carbon::setTestNow(Carbon::parse($local, 'Asia/Jakarta')->utc());
}

test('FR-035 BR-066 absen masuk online: 201, waktu server, telat dihitung, bentuk data 05 §5.4', function () {
    wib('2026-11-16 08:07:30');

    $response = AttendanceWorld::post($this, $this->phone, ['client_time' => '2026-11-16T07:00:00+07:00']);

    $response->assertCreated()
        ->assertJsonPath('data.work_date', '2026-11-16')
        ->assertJsonPath('data.status', 'late')
        ->assertJsonPath('data.late_minutes', 7)
        ->assertJsonPath('data.shift.code', 'PAGI')
        ->assertJsonPath('data.shift.start', '08:00')
        ->assertJsonPath('data.shift.end', '16:00')
        ->assertJsonPath('data.check_in_at', '2026-11-16T08:07:30+07:00')
        ->assertJsonPath('data.check_out_at', null)
        ->assertJsonPath('data.location.name', 'Gudang Uji')
        ->assertJsonPath('data.location.distance_m', 0)
        ->assertJsonPath('data.is_offline', false)
        ->assertJsonPath('meta.replayed', false);

    // Online memakai waktu server, bukan client_time (BR-066/SEC-024).
    $log = AttendanceLog::query()->sole();
    expect($log->recorded_at->toIso8601String())->toBe('2026-11-16T01:07:30+00:00')
        ->and($log->within_radius)->toBeTrue()
        ->and($log->device_id)->toBe($this->phone['device']->id)
        ->and(AuditLog::query()->where('event', 'attendance.recorded')->sole()->new_values)
        ->not->toHaveKeys(['latitude', 'longitude', 'selfie_path']);
});

test('BR-066 toleransi telat: masuk dalam toleransi = present (telat 0); lewat toleransi = menit dari jam mulai', function () {
    $this->w->s->pagi->forceFill(['late_tolerance_min' => 10])->save();

    wib('2026-11-16 08:10:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated()->assertJsonPath('data.status', 'present')->assertJsonPath('data.late_minutes', 0);

    $other = $this->w->phone();
    $this->w->s->weekdays($other['employee'], '2026-01-01');
    wib('2026-11-16 08:11:00');
    AttendanceWorld::post($this, $other)->assertCreated()->assertJsonPath('data.late_minutes', 11);
});

test('BR-060 jendela masuk: sebelum start − checkin_open_min ditolak OUTSIDE_WINDOW; tepat batas diterima', function () {
    wib('2026-11-16 05:59:00');
    AttendanceWorld::post($this, $this->phone)->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUTSIDE_WINDOW');

    wib('2026-11-16 06:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated()->assertJsonPath('data.late_minutes', 0);
});

test('BR-066 masuk kedua → 409 ALREADY_CHECKED_IN; pulang berulang diterima dan yang terakhir dipakai', function () {
    wib('2026-11-16 07:55:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated();

    wib('2026-11-16 08:30:00');
    AttendanceWorld::post($this, $this->phone)->assertStatus(409)->assertJsonPath('error.code', 'ATTENDANCE_ALREADY_CHECKED_IN');

    wib('2026-11-16 15:30:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertCreated()->assertJsonPath('data.status', 'early_leave');

    wib('2026-11-16 16:05:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertCreated()
        ->assertJsonPath('data.check_out_at', '2026-11-16T16:05:00+07:00')
        ->assertJsonPath('data.status', 'present');

    $day = AttendanceDay::query()->sole();
    expect(AttendanceLog::query()->count())->toBe(3)
        ->and($day->early_leave_minutes)->toBe(0)
        ->and($day->work_minutes)->toBe(490);
});

test('BR-060 pulang terlalu cepat setelah masuk (< min_minutes_between_in_out) ditolak OUTSIDE_WINDOW', function () {
    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated();

    wib('2026-11-16 08:03:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OUTSIDE_WINDOW');
});

test('BR-066 pulang tanpa masuk → 409 NOT_CHECKED_IN; diizinkan setting → hari tercatat tanpa masuk', function () {
    wib('2026-11-16 16:00:00');
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertStatus(409)->assertJsonPath('error.code', 'ATTENDANCE_NOT_CHECKED_IN');

    setSetting('attendance.allow_checkout_without_checkin', true);
    AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertCreated()
        ->assertJsonPath('data.check_in_at', null)
        ->assertJsonPath('data.check_out_at', '2026-11-16T16:00:00+07:00');
});

test('BR-063 shift lintas tengah malam: pulang 06.05 → work_date kemarin', function () {
    $malam = ScheduleWorld::shift(['code' => 'MALAM', 'name' => 'Malam', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true]);
    $phone = $this->w->phone();
    $this->w->s->pattern($phone['employee'], '2026-01-01', ['mon' => $malam, 'tue' => $malam]);

    wib('2026-11-16 21:55:00');
    AttendanceWorld::post($this, $phone)->assertCreated()->assertJsonPath('data.work_date', '2026-11-16');

    wib('2026-11-17 06:05:00');
    AttendanceWorld::post($this, $phone, ['type' => 'out'])->assertCreated()
        ->assertJsonPath('data.work_date', '2026-11-16')
        ->assertJsonPath('data.check_out_at', '2026-11-17T06:05:00+07:00');

    $day = AttendanceDay::query()->where('employee_id', $phone['employee']->id)->sole();
    expect($day->work_date->toDateString())->toBe('2026-11-16')
        ->and($day->scheduled_end?->toIso8601String())->toBe('2026-11-16T23:00:00+00:00');
});

test('BR-063 tanpa jadwal (libur pola / hari libur) → NO_SCHEDULE; allow_unscheduled → tanggal lokal tanpa shift', function () {
    wib('2026-11-22 09:00:00'); // Minggu: libur di pola
    AttendanceWorld::post($this, $this->phone)->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_NO_SCHEDULE');

    $this->w->s->holiday('2026-11-17', 'Libur Uji');
    wib('2026-11-17 08:00:00');
    AttendanceWorld::post($this, $this->phone)->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_NO_SCHEDULE');

    setSetting('attendance.allow_unscheduled', true);
    wib('2026-11-22 09:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated()
        ->assertJsonPath('data.work_date', '2026-11-22')
        ->assertJsonPath('data.shift', null)
        ->assertJsonPath('data.late_minutes', 0);
});

test('BR-065 lokasi palsu: 422, tidak ada log, security event berkoordinat 3 desimal, notifikasi HRD + kepala lokasi', function () {
    $kepala = $this->w->s->org->employee($this->w->s->org->gudang, attributes: ['full_name' => 'Kepala Gudang Uji']);
    $this->w->s->org->gudang->forceFill(['head_employee_id' => $kepala->id])->save();
    $kepalaUser = $this->w->s->org->actor('kepala_lokasi', $kepala);
    $hrd = $this->w->s->org->actor('hrd');
    $hrdNonaktif = $this->w->s->org->actor('hrd');
    $hrdNonaktif->forceFill(['is_active' => false])->save();
    $orangLain = $this->w->s->org->actor('supervisor');

    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, ['is_mock' => '1', 'latitude' => '-6.5123456', 'longitude' => '110.5654321'])
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_MOCK_LOCATION');

    expect(AttendanceLog::query()->count())->toBe(0)
        ->and(AttendanceDay::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $event = SecurityEvent::query()->where('event', 'attendance_mock_detected')->sole();
    expect($event->context)->toMatchArray(['latitude' => -6.512, 'longitude' => 110.565, 'device_id' => $this->phone['device']->id, 'device_model' => 'Uji Android'])
        ->and(json_encode($event->context))->not->toContain('6.5123456')
        ->and(json_encode($event->context))->not->toContain($this->phone['uid']);

    Notification::assertSentTo([$hrd, $kepalaUser], AttendanceMockDetected::class);
    Notification::assertNotSentTo([$hrdNonaktif, $orangLain, $this->phone['user']], AttendanceMockDetected::class);
});

test('BR-067 SEC-024 offline 70 jam diterima (recorded_at = client_time), 80 jam ditolak OFFLINE_TOO_OLD', function () {
    wib('2026-11-19 06:00:00');

    // Senin 16 Nov 08:00 WIB = 70 jam sebelum Kamis 19 Nov 06:00 WIB.
    AttendanceWorld::post($this, $this->phone, ['is_offline' => '1', 'client_time' => '2026-11-16T08:00:00+07:00'])
        ->assertCreated()
        ->assertJsonPath('data.work_date', '2026-11-16')
        ->assertJsonPath('data.check_in_at', '2026-11-16T08:00:00+07:00')
        ->assertJsonPath('data.is_offline', true);
    expect(AttendanceLog::query()->sole()->recorded_at->toIso8601String())->toBe('2026-11-16T01:00:00+00:00');

    wib('2026-11-19 16:00:00'); // 80 jam setelah Senin 08:00
    AttendanceWorld::post($this, $this->phone, ['is_offline' => '1', 'type' => 'out', 'client_time' => '2026-11-16T08:00:00+07:00'])
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OFFLINE_TOO_OLD');
});

test('BR-067 offline dengan client_time terlalu jauh di masa depan ditolak', function () {
    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, ['is_offline' => '1', 'client_time' => '2026-11-16T08:11:00+07:00'])
        ->assertStatus(422)->assertJsonPath('error.code', 'ATTENDANCE_OFFLINE_TOO_OLD');
    AttendanceWorld::post($this, $this->phone, ['is_offline' => '1', 'client_time' => '2026-11-16T08:09:00+07:00'])->assertCreated();
});

test('BR-067 jam perangkat meleset > max_clock_skew_minutes: tetap diterima, flags.clock_skew_s & has_anomaly', function () {
    wib('2026-11-16 07:59:00');
    AttendanceWorld::post($this, $this->phone, ['device_clock_offset_s' => '-900'])->assertCreated();

    expect(AttendanceLog::query()->sole()->flags)->toBe(['clock_skew_s' => 900])
        ->and(AttendanceDay::query()->sole()->has_anomaly)->toBeTrue();
});

test('BR-067 SEC-024 replay client_uuid → 200 replayed dengan data lama, payload baru diabaikan; milik orang lain → 409', function () {
    $uuid = (string) Str::uuid();
    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, ['client_uuid' => $uuid])->assertCreated();

    wib('2026-11-16 09:00:00');
    AttendanceWorld::post($this, $this->phone, ['client_uuid' => $uuid, 'type' => 'out', 'latitude' => '-6.9'])
        ->assertOk()
        ->assertJsonPath('meta.replayed', true)
        ->assertJsonPath('data.check_in_at', '2026-11-16T08:00:00+07:00')
        ->assertJsonPath('data.check_out_at', null);
    expect(AttendanceLog::query()->count())->toBe(1);

    $other = $this->w->phone();
    $this->w->s->weekdays($other['employee'], '2026-01-01');
    AttendanceWorld::post($this, $other, ['client_uuid' => $uuid])
        ->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT')
        ->assertJsonMissingPath('data');
});

test('05 §5.4 validasi field: UUID, tipe, waktu ber-offset, rentang Indonesia → 422 VALIDATION_FAILED', function () {
    wib('2026-11-16 08:00:00');

    $response = AttendanceWorld::post($this, $this->phone, [
        'client_uuid' => 'bukan-uuid', 'type' => 'masuk', 'client_time' => '2026-11-16 08:00:00',
        'latitude' => '40.7', 'longitude' => '-74.0', 'accuracy_m' => '',
    ]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(array_keys($response->json('error.details')))->toEqualCanonicalizing(['client_uuid', 'type', 'client_time', 'latitude', 'longitude', 'accuracy_m']);
});

test('06 §4 attendance.self: akun tanpa izin absen → 403 FORBIDDEN', function () {
    $tanpaIzin = $this->w->phone(role: 'administrator');
    $tanpaIzin['user']->syncPermissions([]);
    foreach ($tanpaIzin['user']->roles as $role) {
        $role->revokePermissionTo('attendance.self');
    }
    app()->forgetScopedInstances();

    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $tanpaIzin)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
});

test('SEC-068 unggah absen dibatasi 10 per menit per pengguna → 429 RATE_LIMITED', function () {
    wib('2026-11-16 08:00:00');
    for ($i = 0; $i < 10; $i++) {
        AttendanceWorld::post($this, $this->phone, ['type' => 'out'])->assertStatus(409);
    }

    AttendanceWorld::post($this, $this->phone)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

test('05 §5.4 today: jadwal hari ini, absen hari ini, dan aksi berikutnya', function () {
    wib('2026-11-16 07:00:00');
    AttendanceWorld::today($this, $this->phone)->assertOk()
        ->assertJsonPath('data.work_date', '2026-11-16')
        ->assertJsonPath('data.schedule.kind', 'shift')
        ->assertJsonPath('data.schedule.shift.code', 'PAGI')
        ->assertJsonPath('data.attendance', null)
        ->assertJsonPath('data.next_action', 'in');

    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone)->assertCreated();
    AttendanceWorld::today($this, $this->phone)->assertOk()
        ->assertJsonPath('data.attendance.check_in_at', '2026-11-16T08:00:00+07:00')
        ->assertJsonPath('data.next_action', 'out');

    wib('2026-11-22 09:00:00');
    AttendanceWorld::today($this, $this->phone)->assertOk()
        ->assertJsonPath('data.schedule.kind', 'off')
        ->assertJsonPath('data.next_action', null);
});

test('05 §5.4 today shift malam: setelah tengah malam masih menampilkan hari kerja kemarin yang belum pulang', function () {
    $malam = ScheduleWorld::shift(['code' => 'MALAM', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true]);
    $phone = $this->w->phone();
    $this->w->s->pattern($phone['employee'], '2026-01-01', ['mon' => $malam]);

    wib('2026-11-16 22:00:00');
    AttendanceWorld::post($this, $phone)->assertCreated();

    wib('2026-11-17 02:00:00');
    AttendanceWorld::today($this, $phone)->assertOk()
        ->assertJsonPath('data.work_date', '2026-11-16')
        ->assertJsonPath('data.next_action', 'out');
});

test('SEC-033 pegawai hanya mencatat absen untuk dirinya sendiri (employee dari akun, bukan dari input)', function () {
    $other = $this->w->phone();
    wib('2026-11-16 08:00:00');
    AttendanceWorld::post($this, $this->phone, ['employee_id' => $other['employee']->id])->assertCreated();

    expect(AttendanceLog::query()->sole()->employee_id)->toBe($this->phone['employee']->id)
        ->and(User::query()->find($this->phone['user']->id)?->employee_id)->toBe($this->phone['employee']->id);
});
