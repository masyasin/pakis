<?php

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CorrectionWorld;

/*
 * Cakupan BR-069 per kriteria (matriks 10 "Cakupan BR-060–BR-070"): kriteria jam usulan yang belum
 * teruji di CorrectionApiTest. Hari ini = Senin 16 Nov 2026 12:00 WIB; shift PAGI 08:00–16:00
 * Senin–Jumat (Minggu tidak dijadwalkan). Data fiktif.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

test('BR-069 koreksi pulang saja: minimal jeda dihitung dari jam masuk yang sudah tercatat hari itu', function () {
    AttendanceDay::query()->create([
        'employee_id' => $this->c->staff['employee']->id,
        'work_date' => '2026-11-16',
        'check_in_at' => CarbonImmutable::parse('2026-11-16 08:00', 'Asia/Jakarta')->utc(),
        'status' => AttendanceStatus::Present,
    ]);
    $checkOut = ['type' => 'check_out', 'proposed_check_in' => null];

    CorrectionWorld::submit($this, $this->c->staff, [...$checkOut, 'proposed_check_out' => '08:03'])
        ->assertStatus(422)->assertJsonPath('error.details.proposed_check_out.0', 'Jam pulang minimal 5 menit setelah jam masuk.');

    CorrectionWorld::submit($this, $this->c->staff, [...$checkOut, 'proposed_check_out' => '08:10'])->assertCreated();
});

test('BR-069 tanggal tanpa shift: jam usulan bebas (tidak dibatasi jendela shift)', function () {
    // Minggu 15 Nov 2026: tidak dijadwalkan pada pola Senin–Jumat.
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-15', 'type' => 'both', 'proposed_check_in' => '03:00', 'proposed_check_out' => '23:30'])
        ->assertCreated()
        ->assertJsonPath('data.proposed_check_in', '03:00')
        ->assertJsonPath('data.proposed_check_out', '23:30');

    expect(AttendanceCorrection::query()->sole()->work_date->toDateString())->toBe('2026-11-15');
});
