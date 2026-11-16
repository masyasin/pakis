<?php

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Employee\Models\Employee;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CorrectionWorld;

/*
 * Riwayat & rekap presensi milik sendiri di Pakis Pro (T-043): 05 §5.5–§5.6, 07 §3.4
 * `my_attendance_days`, BR-072, SEC-033. Hari ini = Senin 16 Nov 2026 12:00 WIB.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $attributes */
function myDay(Employee $employee, string $date, AttendanceStatus $status, array $attributes = []): AttendanceDay
{
    $in = in_array($status, [AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::EarlyLeave, AttendanceStatus::NoCheckout], true)
        ? CarbonImmutable::parse($date.' 08:05', 'Asia/Jakarta')->utc()
        : null;

    return AttendanceDay::query()->create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'shift_id' => test()->c->w->s->pagi->id,
        'check_in_at' => $in,
        'status' => $status,
        ...$attributes,
    ]);
}

test('FR-041 riwayat my_attendance_days: hanya milik sendiri, default bulan berjalan, urut -work_date (SEC-033)', function () {
    $own = $this->c->staff['employee'];
    myDay($own, '2026-11-16', AttendanceStatus::Late, ['late_minutes' => 5]);
    myDay($own, '2026-11-13', AttendanceStatus::Present);
    myDay($own, '2026-10-30', AttendanceStatus::Present);
    // Milik pegawai lain (atasan) tidak pernah ikut.
    myDay($this->c->boss['employee'], '2026-11-16', AttendanceStatus::Present);

    $response = CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance')->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('meta.pagination.per_page', 20)
        ->assertJsonPath('data.0.work_date', '2026-11-16')
        ->assertJsonPath('data.0.status', 'late')
        ->assertJsonPath('data.0.late_minutes', 5)
        ->assertJsonPath('data.0.shift.code', $this->c->w->s->pagi->code)
        ->assertJsonPath('data.0.check_in_at', '2026-11-16T08:05:00+07:00')
        ->assertJsonPath('data.1.work_date', '2026-11-13');
    expect($response->json('data.0'))->not->toHaveKeys(['latitude', 'longitude', 'selfie_path']);
});

test('FR-041 riwayat: filter bulan & status, sort naik, cari nama shift', function () {
    $own = $this->c->staff['employee'];
    myDay($own, '2026-10-29', AttendanceStatus::Absent);
    myDay($own, '2026-10-30', AttendanceStatus::Present);
    myDay($own, '2026-11-02', AttendanceStatus::Present);

    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance?filter[month]=2026-10&sort=work_date')->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.work_date', '2026-10-29');

    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance?filter[month]=2026-10&filter[status][]=absent')->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.status', 'absent');

    $name = urlencode(mb_substr($this->c->w->s->pagi->name, 0, 3));
    CorrectionWorld::call($this, $this->c->staff, 'GET', "/api/v1/attendance?q={$name}")->assertOk()
        ->assertJsonPath('meta.pagination.total', 1);
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance?q=zzztidakada')->assertOk()
        ->assertJsonPath('meta.pagination.total', 0);
});

test('FR-041 BR-072 rekap bulan sendiri: hadir = ada presensi masuk, hari kerja = hadir + tidak hadir, koreksi menunggu', function () {
    $own = $this->c->staff['employee'];
    myDay($own, '2026-11-02', AttendanceStatus::Present);
    myDay($own, '2026-11-03', AttendanceStatus::Late, ['late_minutes' => 7]);
    myDay($own, '2026-11-04', AttendanceStatus::EarlyLeave, ['early_leave_minutes' => 30]);
    myDay($own, '2026-11-05', AttendanceStatus::NoCheckout);
    myDay($own, '2026-11-06', AttendanceStatus::Absent);
    myDay($own, '2026-11-07', AttendanceStatus::Off);
    myDay($own, '2026-11-10', AttendanceStatus::Holiday);
    myDay($own, '2026-10-30', AttendanceStatus::Absent);
    myDay($this->c->boss['employee'], '2026-11-02', AttendanceStatus::Absent);
    AttendanceCorrection::query()->create([
        'number' => 'KOR-202611-0099',
        'employee_id' => $own->id,
        'work_date' => '2026-11-06',
        'type' => CorrectionType::CheckIn,
        'proposed_check_in' => '08:00:00',
        'reason_id' => (int) ReferenceItem::query()->forGroup(ReferenceGroup::CorrectionReason)->value('id'),
        'note' => 'Lupa menekan tombol Masuk',
        'status' => CorrectionStatus::Pending,
        'submitted_by' => $this->c->staff['user']->id,
    ]);

    $data = CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/summary?month=2026-11')->assertOk()->json('data');

    expect($data)->toBe([
        'month' => '2026-11',
        'scheduled_days' => 5,
        'present' => 4,
        'late' => 1,
        'early_leave' => 1,
        'no_checkout' => 1,
        'absent' => 1,
        'holiday' => 1,
        'off' => 1,
        'late_minutes_total' => 7,
        'corrections_pending' => 1,
    ]);

    // Tanpa parameter = bulan berjalan; bulan tanpa data = semua nol.
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/summary')->assertOk()
        ->assertJsonPath('data.month', '2026-11')
        ->assertJsonPath('data.present', 4);
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/summary?month=2026-09')->assertOk()
        ->assertJsonPath('data.scheduled_days', 0)
        ->assertJsonPath('data.corrections_pending', 0);
});

test('BR-072 rekap: bulan salah format → 422 VALIDATION_FAILED', function () {
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/summary?month=11-2026')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

test('FR-041 riwayat & rekap: tanpa attendance.self → 403', function () {
    $this->c->staff['user']->syncRoles([]);
    $this->c->staff['user']->givePermissionTo('attendance_corrections.create');
    app()->forgetScopedInstances();

    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance')->assertForbidden();
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/summary')->assertForbidden();
});
