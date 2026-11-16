<?php

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AssertsIdor;
use Tests\Support\CorrectionWorld;

/*
 * T-041 — Koreksi presensi web (FR-038, BR-069, SEC-031, SEC-033, 05 §4.10, 07 §3.4
 * `attendance_corrections`) + presensi manual oleh admin/atasan (keputusan Mas 16 Nov 2026).
 * Hari ini = Senin 16 Nov 2026 12:00 WIB; shift PAGI 08:00–16:00. Data fiktif.
 */

uses(AssertsIdor::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
    $this->org = $this->c->w->s->org;
    $this->hrd = $this->org->actor('hrd');
});

afterEach(fn () => Carbon::setTestNow());

/** Ajukan koreksi lewat API (jalur asli) lalu kembalikan modelnya. */
function cwSubmit(object $test, array $phone, array $payload = []): AttendanceCorrection
{
    $id = CorrectionWorld::submit($test, $phone, $payload)->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    app()->forgetScopedInstances();

    return AttendanceCorrection::query()->findOrFail($id);
}

/** Request web sebagai pengguna (cache scope dibuang agar tiap aktor dihitung ulang). */
function cwAs(object $test, User $user): object
{
    // Request API sebelumnya (auth:sanctum) mengganti guard default; web memakai guard sesi.
    app('auth')->forgetGuards();
    app('auth')->shouldUse('web');
    app()->forgetScopedInstances();

    return $test->actingAs($user, 'web')->withSession(['auth.password_confirmed_at' => time()]);
}

function cwManual(array $override = []): array
{
    return [
        'work_date' => '2026-11-16',
        'type' => 'both',
        'proposed_check_in' => '08:00',
        'proposed_check_out' => '16:00',
        'reason_code' => 'phone_broken',
        'note' => 'HP pegawai rusak, presensi dicatat atasan',
        ...$override,
    ];
}

test('FR-038 CorrectionWeb: default filter pending, cari nomor/nama, filter status, sort tanggal kerja', function () {
    $a = cwSubmit($this, $this->c->staff);
    $b = cwSubmit($this, $this->c->staff, ['work_date' => '2026-11-13']);
    $b->update(['status' => CorrectionStatus::Rejected, 'pending_key' => null]);

    cwAs($this, $this->hrd)->get('/koreksi-absen')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('attendance-corrections/index')
        ->has('rows', 1)
        ->where('rows.0.number', $a->number)
        ->where('rows.0.can_decide', true)
        ->where('listing.state.filters.status', ['pending']));

    cwAs($this, $this->hrd)->get('/koreksi-absen?filter[status][]=rejected')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $b->id));
    cwAs($this, $this->hrd)->get('/koreksi-absen?filter[status][]=pending&filter[status][]=rejected&sort=work_date')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 2)->where('rows.0.id', $b->id));
    cwAs($this, $this->hrd)->get('/koreksi-absen?q='.$a->number)
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $a->id));
    cwAs($this, $this->hrd)->get('/koreksi-absen?q=TIDAKADA')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

test('SEC-033 CorrectionWeb: scope kepala_lokasi & supervisor teruji; di luar scope → 404', function () {
    $gudang = cwSubmit($this, $this->c->staff);
    $tokoStaff = $this->c->member(location: $this->org->toko);
    $toko = cwSubmit($this, $tokoStaff);

    $kepalaGudang = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->gudang));
    cwAs($this, $kepalaGudang)->get('/koreksi-absen')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $gudang->id));
    cwAs($this, $kepalaGudang)->get("/koreksi-absen/{$gudang->id}")->assertOk();
    $this->assertOutOfScope404($kepalaGudang, 'GET', "/koreksi-absen/{$toko->id}");

    // Filter lokasi (R): HRD melihat dua, disaring ke toko → satu.
    cwAs($this, $this->hrd)->get('/koreksi-absen?filter[location]='.$this->org->toko->id)
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $toko->id));

    // Supervisor (atasan langsung): hanya bawahannya; tombol putuskan aktif.
    cwAs($this, $this->c->boss['user'])->get('/koreksi-absen')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.id', $gudang->id)->where('rows.0.can_decide', true));
    $this->assertOutOfScope404($this->c->boss['user'], 'GET', "/koreksi-absen/{$toko->id}");

    // Pegawai biasa tanpa `attendance_corrections.view` → 403.
    cwAs($this, $this->c->staff['user'])->get('/koreksi-absen')->assertForbidden();
});

test('SEC-031 CorrectionWeb: IDOR approve/reject/lampiran → 404; milik sendiri → 403; tanpa wewenang 06 §6 → 403', function () {
    $correction = cwSubmit($this, $this->c->staff);
    $kepalaToko = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->toko));

    $this->assertOutOfScope404($kepalaToko, 'POST', "/koreksi-absen/{$correction->id}/setujui");
    $this->assertOutOfScope404($kepalaToko, 'POST', "/koreksi-absen/{$correction->id}/tolak", ['note' => 'Bukan wewenang']);
    $this->assertOutOfScope404($kepalaToko, 'GET', "/koreksi-absen/{$correction->id}/lampiran");

    // Pengaju yang juga punya hak approve (mis. supervisor) tidak boleh menyetujui miliknya.
    $selfBoss = $this->c->member(role: 'supervisor');
    $own = cwSubmit($this, $selfBoss, ['work_date' => '2026-11-13']);
    cwAs($this, $selfBoss['user'])->post("/koreksi-absen/{$own->id}/setujui")->assertForbidden();
    cwAs($this, $selfBoss['user'])->get("/koreksi-absen/{$own->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.decide', false)->where('can.own', true));

    // Supervisor dengan scope tim melihat bawahan TIDAK langsung, tetapi tidak berwenang memutuskan.
    $grand = $this->c->member($this->c->staff['employee']);
    $indirect = cwSubmit($this, $grand);
    $indirect->update(['approver_employee_id' => $this->c->staff['employee']->id]);
    cwAs($this, $this->c->boss['user'])->post("/koreksi-absen/{$indirect->id}/setujui")->assertForbidden();

    expect($correction->fresh()->status)->toBe(CorrectionStatus::Pending);
});

test('BR-069 CorrectionWeb: setujui memperbarui presensi + audit; tolak wajib alasan; keputusan ganda = galat form', function () {
    $correction = cwSubmit($this, $this->c->staff);

    cwAs($this, $this->c->boss['user'])->post("/koreksi-absen/{$correction->id}/tolak", ['note' => ''])
        ->assertSessionHasErrors('note');

    cwAs($this, $this->c->boss['user'])->from('/koreksi-absen')->post("/koreksi-absen/{$correction->id}/setujui", ['note' => 'Oke'])
        ->assertRedirect('/koreksi-absen')->assertSessionHasNoErrors();
    expect($correction->fresh()->status)->toBe(CorrectionStatus::Approved)
        ->and(AttendanceDay::query()->sole()->is_corrected)->toBeTrue()
        ->and(AuditLog::query()->where('event', 'attendance_correction.approved')->exists())->toBeTrue();

    cwAs($this, $this->hrd)->post("/koreksi-absen/{$correction->id}/tolak", ['note' => 'Terlambat diputuskan'])
        ->assertSessionHasErrors('note');
    expect($correction->fresh()->status)->toBe(CorrectionStatus::Approved);

    // Detail: data asli vs usulan + keputusan.
    cwAs($this, $this->hrd)->get("/koreksi-absen/{$correction->id}")->assertInertia(fn (Assert $page) => $page
        ->component('attendance-corrections/show')
        ->where('correction.status', 'approved')
        ->where('correction.decision_note', 'Oke')
        ->where('day.check_in', '07:55')
        ->where('can.decide', false));
});

test('SEC-013 CorrectionWeb: lampiran diunduh sebagai attachment tanpa cache, hanya dalam scope', function () {
    $correction = cwSubmit($this, $this->c->staff, ['attachment' => CorrectionWorld::pdf()]);

    $response = cwAs($this, $this->hrd)->get("/koreksi-absen/{$correction->id}/lampiran");
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect((string) $response->headers->get('content-disposition'))->toStartWith('attachment')->toContain($correction->number)
        ->and((string) $response->headers->get('cache-control'))->toContain('no-store');

    $none = cwSubmit($this, $this->c->staff, ['work_date' => '2026-11-13']);
    cwAs($this, $this->hrd)->get("/koreksi-absen/{$none->id}/lampiran")->assertNotFound();
});

test('FR-038 CorrectionWeb: ekspor ber-scope tanpa path lampiran', function () {
    cwSubmit($this, $this->c->staff, ['attachment' => CorrectionWorld::pdf()]);
    cwSubmit($this, $this->c->member(location: $this->org->toko));

    $kepalaGudang = $this->org->actor('kepala_lokasi', $this->org->employee($this->org->gudang));
    $response = cwAs($this, $kepalaGudang)->get('/koreksi-absen/ekspor');
    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'koreksi').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $rows = iterator_to_array((new SpreadsheetReader)->rows($path, 'xlsx'), false);
    expect($rows[0])->toContain('Nomor', 'Alasan', 'Status', 'Presensi manual')->not->toContain('Lampiran')
        ->and(count($rows))->toBe(2)
        ->and(implode('|', array_map(fn ($r) => implode('|', array_map('strval', $r)), $rows)))->not->toContain('corrections/');
});

test('BR-069 CorrectionWeb: isi presensi manual dari web — langsung disetujui, galat diterjemahkan ke form', function () {
    $staffId = $this->c->staff['employee']->id;

    // Form tersedia untuk pemegang approve (alasan aktif + batas tanggal).
    cwAs($this, $this->c->boss['user'])->get('/koreksi-absen')->assertInertia(fn (Assert $page) => $page
        ->where('can.manual', true)
        ->where('manual.max_date', '2026-11-16')
        ->where('manual.min_date', '2026-11-09'));
    cwAs($this, $this->org->actor('administrator'))->get('/koreksi-absen')
        ->assertInertia(fn (Assert $page) => $page->where('manual', null));

    cwAs($this, $this->c->boss['user'])->from('/koreksi-absen')
        ->post('/koreksi-absen/manual', cwManual(['employee_id' => $staffId]))
        ->assertRedirect('/koreksi-absen')->assertSessionHasNoErrors();
    $manual = AttendanceCorrection::query()->sole();
    expect($manual->status)->toBe(CorrectionStatus::Approved)
        ->and($manual->isManual())->toBeTrue()
        ->and(AttendanceDay::query()->sole()->status)->toBe(AttendanceStatus::Present);

    // Untuk diri sendiri → 403 (SEC-031).
    cwAs($this, $this->c->boss['user'])->post('/koreksi-absen/manual', cwManual(['employee_id' => $this->c->boss['employee']->id]))
        ->assertForbidden();

    // Pegawai di luar scope → galat umum "tidak ditemukan" (SEC-033).
    $outsider = $this->org->employee($this->org->toko);
    cwAs($this, $this->c->boss['user'])->post('/koreksi-absen/manual', cwManual(['employee_id' => $outsider->id]))
        ->assertSessionHasErrors(['employee_id' => 'Pegawai tidak ditemukan.']);

    // Tanggal di luar batas BR-069 & pengajuan pending pada tanggal yang sama → galat field.
    cwAs($this, $this->c->boss['user'])->post('/koreksi-absen/manual', cwManual(['employee_id' => $staffId, 'work_date' => '2026-11-01']))
        ->assertSessionHasErrors('work_date');
    cwSubmit($this, $this->c->staff, ['work_date' => '2026-11-13']);
    cwAs($this, $this->c->boss['user'])->post('/koreksi-absen/manual', cwManual(['employee_id' => $staffId, 'work_date' => '2026-11-13']))
        ->assertSessionHasErrors('work_date');

    // Tanpa izin approve → 403 di middleware.
    cwAs($this, $this->org->actor('administrator'))->post('/koreksi-absen/manual', cwManual(['employee_id' => $staffId]))
        ->assertForbidden();
});

test('T-041 CorrectionWeb: detail presensi memuat riwayat koreksi & tombol presensi manual', function () {
    cwAs($this, $this->c->boss['user'])->post('/koreksi-absen/manual', cwManual(['employee_id' => $this->c->staff['employee']->id]))
        ->assertSessionHasNoErrors();
    $day = AttendanceDay::query()->sole();

    cwAs($this, $this->c->boss['user'])->get("/absensi/{$day->id}")->assertInertia(fn (Assert $page) => $page
        ->has('corrections', 1)
        ->where('corrections.0.status', 'approved')
        ->where('can.manual', true));
    cwAs($this, $this->org->actor('administrator'))->get("/absensi/{$day->id}")
        ->assertInertia(fn (Assert $page) => $page->where('can.manual', false)->where('manual', null));
});
