<?php

use App\Domain\Attendance\Actions\CreateHoliday;
use App\Domain\Attendance\Actions\DeleteHoliday;
use App\Domain\Attendance\Actions\DeleteScheduleOverride;
use App\Domain\Attendance\Actions\SetScheduleOverride;
use App\Domain\Attendance\AttendanceStatusResolver;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Support\DayStatusResolver;
use App\Domain\Employee\Events\AssignmentChanged;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Support\ScheduleWorld;

/*
 * T-037 — resolver status harian (BR-066, BR-068, BR-052, OQ-021). Tanggal uji: Rabu 11 Nov 2026,
 * zona Asia/Jakarta. Shift PAGI 08.00–16.00 (toleransi telat 5 menit, jendela pulang s.d. 22.00),
 * MALAM 22.00–06.00 lintas tengah malam. Jam tutup hari default 23.30.
 */

const SR_DATE = '2026-11-11';

beforeEach(function () {
    $this->w = ScheduleWorld::make($this);
    $this->pagi = ScheduleWorld::shift(['code' => 'PG5', 'name' => 'Pagi uji', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'late_tolerance_min' => 5, 'checkout_close_min' => 360]);
    $this->malam = ScheduleWorld::shift(['code' => 'MLM', 'name' => 'Malam uji', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'crosses_midnight' => true, 'checkout_close_min' => 360]);
});

/** Jam dinding lokal → UTC. */
function srLocal(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'Asia/Jakarta')->utc();
}

function srEmployee(ScheduleWorld $w, ?Shift $shift, string $join = '2026-01-01'): Employee
{
    $employee = $w->org->employee(attributes: ['join_date' => $join]);
    if ($shift !== null) {
        $w->pattern($employee, $join, array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat'], $shift));
    }

    return $employee;
}

/** Baris hari yang sudah ada absennya (seperti hasil RecordAttendance), jam = waktu lokal penuh. */
function srDay(Employee $employee, ?string $in, ?string $out, string $date = SR_DATE): AttendanceDay
{
    return AttendanceDay::query()->create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'check_in_at' => $in === null ? null : srLocal($in),
        'check_out_at' => $out === null ? null : srLocal($out),
        'status' => AttendanceStatus::Present,
    ]);
}

function srResolve(string $date = SR_DATE): void
{
    app(AttendanceStatusResolver::class)->resolveDate($date);
}

function srStatus(Employee $employee, string $date = SR_DATE): ?AttendanceStatus
{
    return AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', $date)->first()?->status;
}

test('BR-068 StatusResolver tabel kebenaran', function (string $shift, Closure $setup, string $now, ?string $expected) {
    $employee = srEmployee($this->w, $shift === 'none' ? null : $this->{$shift});
    $setup($this->w, $employee);

    $this->travelTo(srLocal($now));
    srResolve();

    expect(srStatus($employee)?->value)->toBe($expected);
})->with([
    'libur resmi, tanpa absen → holiday' => ['pagi', fn (ScheduleWorld $w) => $w->holiday(SR_DATE, 'Libur Uji'), SR_DATE.' 10:00', 'holiday'],
    'libur + pengecualian shift, masuk telat → late' => ['pagi', function (ScheduleWorld $w, Employee $e) {
        $w->holiday(SR_DATE, 'Libur Uji');
        $w->override($e, SR_DATE, Shift::query()->where('code', 'PG5')->firstOrFail());
        srDay($e, SR_DATE.' 08:20', SR_DATE.' 16:00');
    }, SR_DATE.' 17:00', 'late'],
    'libur tanpa pengecualian walau ada absen → holiday' => ['pagi', function (ScheduleWorld $w, Employee $e) {
        $w->holiday(SR_DATE, 'Libur Uji');
        srDay($e, SR_DATE.' 08:20', null);
    }, SR_DATE.' 12:00', 'holiday'],
    'pengecualian libur → off' => ['pagi', fn (ScheduleWorld $w, Employee $e) => $w->override($e, SR_DATE, null), SR_DATE.' 10:00', 'off'],
    'belum punya pola → off' => ['none', fn () => null, SR_DATE.' 10:00', 'off'],
    'hari kerja belum ditutup, belum absen → belum ada baris' => ['pagi', fn () => null, SR_DATE.' 12:00', null],
    'hari kerja sudah ditutup, tanpa absen → absent' => ['pagi', fn () => null, SR_DATE.' 23:45', 'absent'],
    'masuk tanpa pulang, jendela pulang belum lewat → present' => ['pagi', fn ($w, Employee $e) => srDay($e, SR_DATE.' 07:55', null), SR_DATE.' 12:00', 'present'],
    'masuk tanpa pulang, jendela pulang lewat → no_checkout' => ['pagi', fn ($w, Employee $e) => srDay($e, SR_DATE.' 07:55', null), SR_DATE.' 22:30', 'no_checkout'],
    'telat melewati toleransi → late' => ['pagi', fn ($w, Employee $e) => srDay($e, SR_DATE.' 08:10', SR_DATE.' 16:00'), SR_DATE.' 17:00', 'late'],
    'tepat di batas toleransi → present' => ['pagi', fn ($w, Employee $e) => srDay($e, SR_DATE.' 08:05', SR_DATE.' 16:00'), SR_DATE.' 17:00', 'present'],
    'pulang sebelum jam selesai → early_leave' => ['pagi', fn ($w, Employee $e) => srDay($e, SR_DATE.' 07:55', SR_DATE.' 15:30'), SR_DATE.' 17:00', 'early_leave'],
    'masuk & pulang tepat → present' => ['pagi', fn ($w, Employee $e) => srDay($e, SR_DATE.' 07:55', SR_DATE.' 16:05'), SR_DATE.' 17:00', 'present'],
    'hanya absen pulang, hari ditutup → absent' => ['pagi', fn ($w, Employee $e) => srDay($e, null, SR_DATE.' 16:00'), SR_DATE.' 23:45', 'absent'],
    'shift malam: masuk 21.55, pulang 06.05 besok → present' => ['malam', fn ($w, Employee $e) => srDay($e, SR_DATE.' 21:55', '2026-11-12 06:05'), '2026-11-12 07:00', 'present'],
    'shift malam, pukul 23.45 belum ditutup → belum ada baris' => ['malam', fn () => null, SR_DATE.' 23:45', null],
    'shift malam, jendela pulang besok lewat → absent' => ['malam', fn () => null, '2026-11-12 12:30', 'absent'],
]);

test('BR-068 StatusResolver: job diulang → hasil identik (idempoten)', function () {
    $absent = srEmployee($this->w, $this->pagi);
    $late = srEmployee($this->w, $this->pagi);
    srDay($late, SR_DATE.' 08:30', SR_DATE.' 16:00');
    $off = srEmployee($this->w, null);

    $this->travelTo(srLocal(SR_DATE.' 23:45'));
    $this->artisan('attendance:resolve-day', ['date' => SR_DATE])->assertSuccessful();
    $first = AttendanceDay::query()->orderBy('id')->get()->toArray();

    $this->travelTo(srLocal(SR_DATE.' 23:59'));
    $this->artisan('attendance:resolve-day', ['date' => SR_DATE])->assertSuccessful()
        ->expectsOutputToContain('0 dibuat, 0 diperbarui');

    expect(AttendanceDay::query()->orderBy('id')->get()->toArray())->toBe($first)
        ->and(array_map(fn (Employee $e) => srStatus($e), [$absent, $late, $off]))
        ->toBe([AttendanceStatus::Absent, AttendanceStatus::Late, AttendanceStatus::Off]);
});

test('BR-066 StatusResolver: hitungan menit & snapshot jadwal dari shift (bukan dari T-036 saja)', function () {
    $employee = srEmployee($this->w, $this->pagi);
    srDay($employee, SR_DATE.' 08:12', SR_DATE.' 15:40');

    $this->travelTo(srLocal(SR_DATE.' 23:45'));
    srResolve();

    $day = AttendanceDay::query()->where('employee_id', $employee->id)->firstOrFail();
    expect([$day->late_minutes, $day->early_leave_minutes, $day->work_minutes])->toBe([12, 20, 448])
        ->and($day->status)->toBe(AttendanceStatus::Late)
        ->and($day->shift_id)->toBe($this->pagi->id)
        ->and($day->scheduled_start?->toIso8601String())->toBe(srLocal(SR_DATE.' 08:00')->toIso8601String())
        ->and($day->location_id)->toBe($this->w->org->gudang->id)
        ->and($day->department_id)->toBe($this->w->org->department->id);
});

test('BR-052 StatusResolver: mutasi → snapshot dihitung ulang mulai tanggal mutasi, tanggal sebelumnya tetap', function () {
    $employee = srEmployee($this->w, $this->pagi);
    $this->travelTo(srLocal('2026-11-12 08:00'));
    srResolve('2026-11-03');
    srResolve(SR_DATE);

    // Mutasi ke toko sebagai kepala gudang mulai 10 Nov (penugasan lama ditutup 9 Nov).
    $old = Assignment::query()->where('employee_id', $employee->id)->firstOrFail();
    $old->forceFill(['end_date' => '2026-11-09'])->save();
    Assignment::query()->create(['employee_id' => $employee->id, 'position_id' => $this->w->org->head->id, 'location_id' => $this->w->org->toko->id, 'is_primary' => true, 'start_date' => '2026-11-10']);
    AssignmentChanged::dispatch($employee->id, '2026-11-10', 'mutated');

    $before = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', '2026-11-03')->firstOrFail();
    $after = AttendanceDay::query()->where('employee_id', $employee->id)->where('work_date', SR_DATE)->firstOrFail();
    expect([$before->location_id, $before->position_id])->toBe([$this->w->org->gudang->id, $this->w->org->staff->id])
        ->and([$after->location_id, $after->position_id])->toBe([$this->w->org->toko->id, $this->w->org->head->id])
        ->and($after->status)->toBe(AttendanceStatus::Absent);
});

test('OQ-021 StatusResolver: pengecualian & hari libur lampau berubah → status yang sudah ada ikut berubah', function () {
    $employee = srEmployee($this->w, $this->pagi);
    $this->travelTo(srLocal('2026-11-12 08:00'));
    srResolve();
    expect(srStatus($employee))->toBe(AttendanceStatus::Absent);

    app(SetScheduleOverride::class)->handle([$employee->id], SR_DATE, null, 'Izin keluarga', null);
    expect(srStatus($employee))->toBe(AttendanceStatus::Off);

    $holiday = app(CreateHoliday::class)->handle(['start_date' => SR_DATE, 'end_date' => SR_DATE, 'name' => 'Libur Uji Mendadak', 'is_national' => false, 'applies_to_all' => true], [], null);
    expect(srStatus($employee))->toBe(AttendanceStatus::Holiday);

    app(DeleteHoliday::class)->handle(Holiday::query()->findOrFail($holiday->id));
    expect(srStatus($employee))->toBe(AttendanceStatus::Off);

    app(DeleteScheduleOverride::class)->handle(ScheduleOverride::query()->where('employee_id', $employee->id)->firstOrFail());
    expect(srStatus($employee))->toBe(AttendanceStatus::Absent);
});

test('BR-068 StatusResolver: pegawai yang belum masuk / sudah keluar pada tanggal itu tidak dicap tidak hadir', function () {
    $newcomer = srEmployee($this->w, $this->pagi, join: '2026-11-12');
    $leaver = srEmployee($this->w, $this->pagi);

    $this->travelTo(srLocal('2026-11-12 08:00'));
    srResolve();
    expect(srStatus($newcomer))->toBeNull()
        ->and(srStatus($leaver))->toBe(AttendanceStatus::Absent);

    // Keluar mundur per 10 Nov: baris turunan (tanpa absen) setelah tanggal keluar dibuang.
    $leaver->forceFill(['status' => 'inactive', 'exit_date' => '2026-11-10'])->save();
    AssignmentChanged::dispatch($leaver->id, '2026-11-10', 'ended');

    expect(srStatus($leaver))->toBeNull();
    srResolve();
    expect(srStatus($leaver))->toBeNull();
});

test('BR-068 StatusResolver: hitung ulang tidak membuat baris untuk tanggal lama yang belum pernah dihitung', function () {
    $employee = srEmployee($this->w, $this->pagi);
    $this->travelTo(srLocal('2026-11-12 08:00'));

    $result = app(AttendanceStatusResolver::class)->recompute([$employee->id], '2026-10-01');

    expect($result['created'])->toBe(0)
        ->and(AttendanceDay::query()->count())->toBe(0);
});

test('T-037 StatusResolver: titik pakai DayStatusResolver = resolver lengkap', function () {
    expect(app(DayStatusResolver::class))->toBeInstanceOf(AttendanceStatusResolver::class);
});

test('T-037 StatusResolver: command tanpa argumen = kemarin & hari ini (tanggal lokal), tanggal masa depan ditolak', function () {
    // 00.30 WIB tanggal 12 = 17.30 UTC tanggal 11: tanggal UTC akan salah satu hari.
    $this->travelTo(srLocal('2026-11-12 00:30'));

    $this->artisan('attendance:resolve-day')
        ->expectsOutputToContain('2026-11-11:')
        ->expectsOutputToContain('2026-11-12:')
        ->assertSuccessful();

    $this->artisan('attendance:resolve-day', ['date' => '2026-11-13'])
        ->expectsOutputToContain('masa depan')
        ->assertFailed();
    $this->artisan('attendance:resolve-day', ['date' => '11-11-2026'])
        ->expectsOutputToContain('Format tanggal')
        ->assertFailed();

    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'attendance:resolve-day'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/15 * * * *');
});
