<?php

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Employee\Import\EmployeeImportReader;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EmployeeWorld;

/*
 * T-039 — Rekap bulanan presensi (FR-039, BR-072, 07 §3.4 `attendance_summary`).
 * Bulan uji: November 2026, "hari ini" 20 Nov (WIB). Angka di bawah dihitung tangan dari fixture.
 */

beforeEach(function () {
    $this->world = EmployeeWorld::make($this);
    $this->hrd = $this->world->actor('hrd');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Jakarta'));
});

/** @param  array<string, mixed>  $attributes */
function sumDay(Employee $employee, string $date, AttendanceStatus $status, Location $location, array $attributes = []): void
{
    AttendanceDay::query()->create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'location_id' => $location->id,
        'department_id' => test()->world->department->id,
        'position_id' => test()->world->staff->id,
        'status' => $status,
        ...$attributes,
    ]);
}

/**
 * A (gudang): hadir, telat 10, telat 5 + pulang cepat 15, tidak absen pulang, tidak hadir, libur, off,
 *   + 1 hadir di Oktober (bulan lain). Hari kerja 5, hadir 4 → 80,0 %.
 * B (toko): 2 hadir → 100 %. C (gudang): hanya libur/off → hari kerja 0, % kosong.
 *
 * @return array{a: Employee, b: Employee, c: Employee}
 */
function sumFixture(EmployeeWorld $w): array
{
    $a = $w->employee($w->gudang, attributes: ['full_name' => 'Andi Rekap']);
    $b = $w->employee($w->toko, attributes: ['full_name' => 'Budi Rekap']);
    $c = $w->employee($w->gudang, attributes: ['full_name' => 'Citra Rekap']);

    sumDay($a, '2026-11-02', AttendanceStatus::Present, $w->gudang);
    sumDay($a, '2026-11-03', AttendanceStatus::Late, $w->gudang, ['late_minutes' => 10]);
    sumDay($a, '2026-11-04', AttendanceStatus::Late, $w->gudang, ['late_minutes' => 5, 'early_leave_minutes' => 15]);
    sumDay($a, '2026-11-05', AttendanceStatus::NoCheckout, $w->gudang);
    sumDay($a, '2026-11-06', AttendanceStatus::Absent, $w->gudang);
    sumDay($a, '2026-11-07', AttendanceStatus::Holiday, $w->gudang);
    sumDay($a, '2026-11-08', AttendanceStatus::Off, $w->gudang);
    sumDay($a, '2026-10-30', AttendanceStatus::Present, $w->gudang);

    sumDay($b, '2026-11-02', AttendanceStatus::Present, $w->toko);
    sumDay($b, '2026-11-03', AttendanceStatus::Present, $w->toko);

    sumDay($c, '2026-11-07', AttendanceStatus::Holiday, $w->gudang);
    sumDay($c, '2026-11-08', AttendanceStatus::Off, $w->gudang);

    return ['a' => $a, 'b' => $b, 'c' => $c];
}

test('BR-072 AttendanceSummary: angka rekap = agregasi attendance_days bulan terpilih (default bulan ini)', function () {
    sumFixture($this->world);

    $this->actingAs($this->hrd)->get('/absensi/rekap')->assertInertia(fn (Assert $page) => $page
        ->component('attendance/summary')
        ->where('month', '2026-11')
        ->has('rows', 3)
        ->where('rows.0', [
            'employee' => ['id' => $page->toArray()['props']['rows'][0]['employee']['id'], 'name' => 'Andi Rekap', 'number' => $page->toArray()['props']['rows'][0]['employee']['number']],
            'location' => 'Gudang Uji',
            'scheduled_days' => 5, 'present_days' => 4, 'late_count' => 2, 'late_minutes_total' => 15,
            'early_leave_count' => 1, 'no_checkout_days' => 1, 'absent_days' => 1, 'off_days' => 2,
            'attendance_pct' => 80, // 80,0 % (JSON menyimpan 80.0 sebagai 80)
        ])
        ->where('rows.1.employee.name', 'Budi Rekap')
        ->where('rows.1.attendance_pct', 100)
        ->where('rows.2.employee.name', 'Citra Rekap')
        ->where('rows.2.scheduled_days', 0)
        ->where('rows.2.off_days', 2)
        ->where('rows.2.attendance_pct', null));

    // Bulan lain: hanya hadir Oktober milik A.
    $this->actingAs($this->hrd)->get('/absensi/rekap?filter[month]=2026-10')->assertInertia(fn (Assert $page) => $page
        ->has('rows', 1)
        ->where('rows.0.employee.name', 'Andi Rekap')
        ->where('rows.0.present_days', 1)
        ->where('rows.0.attendance_pct', 100));
});

test('BR-072 AttendanceSummary: sort % kehadiran & jumlah telat, filter rentang %, lokasi (snapshot), cari', function () {
    sumFixture($this->world);
    $names = fn (string $query) => $this->actingAs($this->hrd)->get('/absensi/rekap?'.$query)->original->getData()['page']['props']['rows'];

    expect(array_column(array_column($names('sort=-attendance_pct'), 'employee'), 'name'))->toBe(['Budi Rekap', 'Andi Rekap', 'Citra Rekap'])
        ->and(array_column(array_column($names('sort=-late_count'), 'employee'), 'name')[0])->toBe('Andi Rekap')
        ->and(array_column(array_column($names('filter[attendance_pct][min]=90'), 'employee'), 'name'))->toBe(['Budi Rekap'])
        ->and(array_column(array_column($names('filter[attendance_pct][max]=85'), 'employee'), 'name'))->toBe(['Andi Rekap'])
        ->and(array_column(array_column($names("filter[location]={$this->world->toko->id}"), 'employee'), 'name'))->toBe(['Budi Rekap'])
        ->and(array_column(array_column($names('q=Citra'), 'employee'), 'name'))->toBe(['Citra Rekap']);
});

test('SEC-033 AttendanceSummary: kepala lokasi hanya menghitung hari di lokasinya; kalender di luar scope = null', function () {
    ['a' => $a, 'b' => $b] = sumFixture($this->world);
    $kepala = $this->world->actor('kepala_lokasi', $this->world->employee($this->world->gudang));

    $this->actingAs($kepala)->get('/absensi/rekap')->assertInertia(fn (Assert $page) => $page
        ->has('rows', 2)
        ->where('rows.0.employee.name', 'Andi Rekap')
        ->where('rows.1.employee.name', 'Citra Rekap'));

    $this->actingAs($kepala)->get("/absensi/rekap?kalender={$a->id}")->assertInertia(fn (Assert $page) => $page
        ->where('calendar.employee.name', 'Andi Rekap')
        ->has('calendar.days', 7)
        ->where('calendar.days.0.date', '2026-11-02')
        ->where('calendar.days.4.status', 'absent'));

    $this->actingAs($kepala)->get("/absensi/rekap?kalender={$b->id}")->assertInertia(fn (Assert $page) => $page->where('calendar', null));
    $this->actingAs($kepala)->get('/absensi/rekap?kalender=abc')->assertInertia(fn (Assert $page) => $page->where('calendar', null));
});

test('FR-039 AttendanceSummary: ekspor sesuai rekap; Administrator tanpa izin ekspor & Pegawai ditolak', function () {
    sumFixture($this->world);

    $response = $this->actingAs($this->hrd)->get('/absensi/rekap/ekspor');
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $rows = iterator_to_array((new EmployeeImportReader)->rows($path, 'xlsx'), false);

    expect($rows[0])->toContain('Hari kerja', '% kehadiran', 'Libur')
        ->and(count($rows))->toBe(4);
    $andi = array_combine($rows[0], $rows[1]);
    expect($andi['Nama'])->toBe('Andi Rekap')
        ->and((int) $andi['Hadir'])->toBe(4)
        ->and((float) $andi['% kehadiran'])->toBe(80.0);

    $this->actingAs($this->world->actor('administrator'))->get('/absensi/rekap')->assertOk();
    $this->actingAs($this->world->actor('administrator'))->get('/absensi/rekap/ekspor')->assertForbidden();
    $this->actingAs($this->world->actor('pegawai', $this->world->employee()))->get('/absensi/rekap')->assertForbidden();
});
