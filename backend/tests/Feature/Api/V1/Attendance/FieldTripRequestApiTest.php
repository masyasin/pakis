<?php

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Attendance\Notifications\FieldTripDecided;
use App\Domain\Attendance\Notifications\FieldTripSubmitted;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\CorrectionWorld;

/*
 * T-036A bagian 2 — pengajuan tugas luar kota dari Pakis Pro + persetujuan (05 §5.10, 06 §6 &
 * §7.2a, BR-071, SEC-031, SEC-033). Hari ini = Senin 16 Nov 2026 12:00 WIB. Data fiktif.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $override */
function ftPayload(array $override = []): array
{
    return [
        'start_date' => '2026-11-18',
        'end_date' => '2026-11-19',
        'destination' => 'Semarang',
        'purpose' => 'Kirim barang ke toko mitra',
        'attendance_rule' => 'destination',
        'destinations' => [['name' => 'Toko Mitra Uji', 'latitude' => -6.9666, 'longitude' => 110.4196, 'radius_m' => 150]],
        ...$override,
    ];
}

function ftSubmit(object $test, array $phone, array $override = []): TestResponse
{
    return CorrectionWorld::call($test, $phone, 'POST', '/api/v1/field-trips', ftPayload($override));
}

test('BR-071 FieldTripRequest: pegawai mengajukan → pending untuk dirinya sendiri, penyetuju diberi tahu, audit; belum membuka lokasi', function () {
    $response = ftSubmit($this, $this->c->staff, ['employee_ids' => [$this->c->boss['employee']->id]])->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.is_request', true)
        ->assertJsonPath('data.days', 2)
        ->assertJsonPath('data.destinations.0.name', 'Toko Mitra Uji')
        ->assertJsonMissingPath('data.destinations.0.latitude');

    // `employee_ids` dari klien diabaikan: pegawai = pemilik token (SEC-033).
    expect($response->json('data.employees'))->toHaveCount(1)
        ->and($response->json('data.employees.0.id'))->toBe($this->c->staff['employee']->id);

    $trip = FieldTrip::query()->sole();
    expect($trip->status)->toBe(FieldTripStatus::Pending)->and($trip->requested_by)->toBe($this->c->staff['user']->id)
        ->and(AuditLog::query()->where('event', 'field_trip.requested')->exists())->toBeTrue();
    Notification::assertSentTo($this->c->boss['user'], FieldTripSubmitted::class);

    // Daftar milik sendiri + kartu beranda belum aktif (pending tidak membuka lokasi).
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/field-trips')->assertOk()->assertJsonPath('meta.pagination.total', 1);
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/today')->assertOk()->assertJsonPath('data.field_trip', null);
});

test('BR-071 FieldTripRequest: validasi sama dengan web — titik tujuan wajib, batas hari, tumpang tindih dengan tugas approved', function () {
    ftSubmit($this, $this->c->staff, ['destinations' => []])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    setSetting('attendance.field_trip_max_days', 3);
    ftSubmit($this, $this->c->staff, ['end_date' => '2026-11-25'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['end_date']]]);

    // Tugas approved buatan atasan di tanggal yang beririsan → galat di `start_date` (field form HP).
    $id = ftSubmit($this, $this->c->staff, ['attendance_rule' => 'anywhere'])->assertCreated()->json('data.id');
    CorrectionWorld::call($this, $this->c->boss, 'POST', "/api/v1/approvals/field-trips/{$id}/approve")->assertOk();
    ftSubmit($this, $this->c->staff, ['start_date' => '2026-11-19', 'end_date' => '2026-11-20'])
        ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['start_date']]]);
});

test('SEC-031 FieldTripRequest: atasan langsung menyetujui → approved & aktif di beranda; keputusan ganda 409; tolak wajib alasan', function () {
    $id = ftSubmit($this, $this->c->staff, ['start_date' => '2026-11-16', 'end_date' => '2026-11-17'])->assertCreated()->json('data.id');

    CorrectionWorld::call($this, $this->c->boss, 'GET', '/api/v1/approvals/field-trips')->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $id);
    CorrectionWorld::call($this, $this->c->boss, 'POST', "/api/v1/approvals/field-trips/{$id}/reject", ['note' => ''])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

    CorrectionWorld::call($this, $this->c->boss, 'POST', "/api/v1/approvals/field-trips/{$id}/approve", ['note' => 'Hati-hati di jalan'])->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by.name', $this->c->boss['user']->name)
        ->assertJsonPath('data.decision_note', 'Hati-hati di jalan');
    Notification::assertSentTo($this->c->staff['user'], FieldTripDecided::class);
    expect(AuditLog::query()->where('event', 'field_trip.approved')->exists())->toBeTrue();

    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/attendance/today')->assertOk()
        ->assertJsonPath('data.field_trip.destination', 'Semarang')
        ->assertJsonPath('data.field_trip.attendance_rule', 'destination');

    $hrd = $this->c->phoneFor($this->c->w->s->org->employee(), 'hrd');
    CorrectionWorld::call($this, $hrd, 'POST', "/api/v1/approvals/field-trips/{$id}/reject", ['note' => 'Terlambat diputuskan'])
        ->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
});

test('SEC-033 FieldTripRequest: milik sendiri 403, di luar wewenang 404, daftar persetujuan tanpa milik sendiri', function () {
    // Atasan yang juga punya izin request mengajukan untuk dirinya → tidak bisa ia putuskan sendiri.
    $own = ftSubmit($this, $this->c->boss)->assertCreated()->json('data.id');
    CorrectionWorld::call($this, $this->c->boss, 'POST', "/api/v1/approvals/field-trips/{$own}/approve")->assertStatus(403);
    CorrectionWorld::call($this, $this->c->boss, 'GET', '/api/v1/approvals/field-trips')->assertOk()->assertJsonPath('meta.pagination.total', 0);

    // Pengajuan staf: kepala lokasi lain & rekan setim tidak berwenang → 404.
    $id = ftSubmit($this, $this->c->staff, ['start_date' => '2026-11-20', 'end_date' => '2026-11-20'])->assertCreated()->json('data.id');
    $org = $this->c->w->s->org;
    $kepalaToko = $this->c->phoneFor($org->employee($org->toko), 'kepala_lokasi');
    CorrectionWorld::call($this, $kepalaToko, 'POST', "/api/v1/approvals/field-trips/{$id}/approve")->assertNotFound();
    CorrectionWorld::call($this, $kepalaToko, 'GET', '/api/v1/approvals/field-trips')->assertOk()->assertJsonPath('meta.pagination.total', 0);

    // Kepala lokasi gudang (scope lokasi) berwenang walau bukan atasan langsung.
    $kepalaGudang = $this->c->phoneFor($org->employee($org->gudang), 'kepala_lokasi');
    CorrectionWorld::call($this, $kepalaGudang, 'GET', '/api/v1/approvals/field-trips')->assertOk()->assertJsonPath('meta.pagination.total', 2);

    // Pegawai tanpa field_trips.approve → 403 di middleware.
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/approvals/field-trips')->assertForbidden();
});

test('T-036A FieldTripRequest web: setujui/tolak dari daftar web; IDOR 404; bentrok saat disetujui = galat form', function () {
    $id = ftSubmit($this, $this->c->staff)->assertCreated()->json('data.id');
    $hrd = $this->c->w->s->org->actor('hrd');

    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    app()->forgetScopedInstances();
    $this->actingAs($hrd, 'web')->get('/tugas-luar-kota')
        ->assertInertia(fn ($page) => $page->where('rows.0.id', $id)->where('rows.0.can.decide', true)->where('rows.0.is_request', true));

    $kepalaToko = $this->c->w->s->org->actor('kepala_lokasi', $this->c->w->s->org->employee($this->c->w->s->org->toko));
    $this->actingAs($kepalaToko, 'web')->post("/tugas-luar-kota/{$id}/setujui")->assertNotFound();

    $this->actingAs($hrd, 'web')->post("/tugas-luar-kota/{$id}/tolak", ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($hrd, 'web')->post("/tugas-luar-kota/{$id}/setujui")->assertSessionHasNoErrors()->assertRedirect();
    expect(FieldTrip::query()->findOrFail($id)->status)->toBe(FieldTripStatus::Approved);
    $this->actingAs($hrd, 'web')->post("/tugas-luar-kota/{$id}/setujui")->assertSessionHasErrors('trip');

    // Pengajuan kedua yang beririsan: diterima saat pending? Tidak — sudah bentrok dengan yang approved.
    ftSubmit($this, $this->c->staff)->assertStatus(422);
    expect(User::query()->count())->toBeGreaterThan(0);
});
