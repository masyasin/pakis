<?php

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceLog;
use App\Domain\Attendance\Notifications\CorrectionDecided;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttendanceWorld;
use Tests\Support\CorrectionWorld;

/*
 * Persetujuan koreksi presensi (T-040): 05 §5.8, 06 §6 & §7.1, BR-069, BR-070, SEC-031.
 * Hari ini = Senin 16 Nov 2026 12:00 WIB; shift PAGI 08:00–16:00.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

function approvalUri(int $id, string $action): string
{
    return "/api/v1/approvals/attendance-corrections/{$id}/{$action}";
}

test('BR-069 disetujui atasan langsung: jam hari diganti, is_corrected, log asli utuh, status dihitung ulang, audit lama/baru, notifikasi pengaju', function () {
    // Pegawai sebenarnya presensi Masuk pukul 08:20 (telat); mengoreksi menjadi 07:55.
    Carbon::setTestNow('2026-11-16 01:20:00');
    AttendanceWorld::post($this, $this->c->staff)->assertCreated();
    $log = AttendanceLog::query()->sole();
    expect(AttendanceDay::query()->sole()->status)->toBe(AttendanceStatus::Late);

    Carbon::setTestNow('2026-11-16 05:00:00');
    $id = CorrectionWorld::submit($this, $this->c->staff)->assertCreated()->json('data.id');

    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'approve'), ['note' => 'Oke'])->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by.name', $this->c->boss['user']->name)
        ->assertJsonPath('data.decision_note', 'Oke');

    $day = AttendanceDay::query()->sole();
    expect($day->check_in_at->toIso8601String())->toBe('2026-11-16T00:55:00+00:00')
        ->and($day->is_corrected)->toBeTrue()
        ->and($day->status)->toBe(AttendanceStatus::Present)
        ->and($day->late_minutes)->toBe(0)
        ->and($day->check_in_log_id)->toBe($log->id)
        ->and($log->fresh()->recorded_at->toIso8601String())->toBe('2026-11-16T01:20:00+00:00');

    $audit = AuditLog::query()->where('event', 'attendance_correction.approved')->sole();
    expect($audit->old_values)->toMatchArray(['status' => 'pending', 'check_in_at' => '2026-11-16 08:20', 'day_status' => 'late', 'is_corrected' => false])
        ->and($audit->new_values)->toMatchArray(['status' => 'approved', 'check_in_at' => '2026-11-16 07:55', 'day_status' => 'present', 'is_corrected' => true]);

    Notification::assertSentTo($this->c->staff['user'], CorrectionDecided::class);
});

test('BR-069 tanggal tanpa baris presensi: baris dibuat dengan snapshot jadwal, lalu dikoreksi', function () {
    $id = CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-13', 'type' => 'both', 'proposed_check_in' => '08:00', 'proposed_check_out' => '16:05'])
        ->assertCreated()->json('data.id');

    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'approve'))->assertOk();

    $day = AttendanceDay::query()->sole();
    expect($day->work_date->toDateString())->toBe('2026-11-13')
        ->and($day->shift_id)->toBe($this->c->w->s->pagi->id)
        ->and($day->location_id)->toBe($this->c->w->s->org->gudang->id)
        ->and($day->scheduled_start?->toIso8601String())->toBe('2026-11-13T01:00:00+00:00')
        ->and($day->check_out_at?->toIso8601String())->toBe('2026-11-13T09:05:00+00:00')
        ->and($day->status)->toBe(AttendanceStatus::Present)
        ->and($day->is_corrected)->toBeTrue()
        ->and($day->check_in_log_id)->toBeNull();
});

test('SEC-031 menyetujui/menolak koreksi milik sendiri → 403 CORRECTION_SELF_APPROVAL', function () {
    // Atasan tanpa atasan sendiri mengajukan koreksinya; ia juga pemegang approve.
    $id = CorrectionWorld::submit($this, $this->c->boss)->assertCreated()->json('data.id');

    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'approve'))
        ->assertForbidden()->assertJsonPath('error.code', 'CORRECTION_SELF_APPROVAL');
    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'reject'), ['note' => 'Tolak sendiri'])
        ->assertForbidden()->assertJsonPath('error.code', 'CORRECTION_SELF_APPROVAL');
    expect(AttendanceCorrection::query()->sole()->status->value)->toBe('pending');

    $correction = AttendanceCorrection::query()->sole();
    expect(Gate::forUser($this->c->boss['user'])->allows('decide', $correction))->toBeFalse();
});

test('06 §6: atasan langsung, kepala lokasi, dan HRD boleh; supervisor lain/atasan tidak langsung & lokasi lain → 404', function () {
    $org = $this->c->w->s->org;
    $bigBoss = $this->c->phoneFor($org->employee($org->gudang), 'supervisor');
    $this->c->boss['employee']->primaryAssignment()->update(['supervisor_employee_id' => $bigBoss['employee']->id]);
    $otherSupervisor = $this->c->phoneFor($org->employee($org->gudang), 'supervisor');
    $kepalaToko = $this->c->phoneFor($org->employee($org->toko), 'kepala_lokasi');
    $kepalaGudang = $this->c->phoneFor($org->employee($org->gudang), 'kepala_lokasi');
    $hrd = $this->c->phoneFor($org->employee($org->toko), 'hrd');

    $ids = [];
    foreach (['2026-11-16', '2026-11-13', '2026-11-12'] as $date) {
        $ids[] = CorrectionWorld::submit($this, $this->c->staff, ['work_date' => $date])->assertCreated()->json('data.id');
    }

    foreach ([$bigBoss, $otherSupervisor, $kepalaToko] as $outsider) {
        CorrectionWorld::call($this, $outsider, 'POST', approvalUri($ids[0], 'approve'))
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($ids[0], 'approve'))->assertOk();
    CorrectionWorld::call($this, $kepalaGudang, 'POST', approvalUri($ids[1], 'approve'))->assertOk();
    CorrectionWorld::call($this, $hrd, 'POST', approvalUri($ids[2], 'reject'), ['note' => 'Bukti kurang'])->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    expect(AttendanceCorrection::query()->pluck('status')->map->value->all())->toEqualCanonicalizing(['approved', 'approved', 'rejected']);
});

test('06 §7.1 tolak wajib catatan 5–500; keputusan kedua → 409 STATE_CONFLICT (status dicek di bawah kunci)', function () {
    $id = CorrectionWorld::submit($this, $this->c->staff)->assertCreated()->json('data.id');

    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'reject'))
        ->assertStatus(422)->assertJsonPath('error.details.note.0', 'Alasan penolakan wajib diisi.');
    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'reject'), ['note' => 'abc'])
        ->assertStatus(422)->assertJsonPath('error.details.note.0', 'Alasan penolakan minimal 5 karakter.');

    $hrd = $this->c->phoneFor($this->c->w->s->org->employee(), 'hrd');
    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'approve'))->assertOk();
    CorrectionWorld::call($this, $hrd, 'POST', approvalUri($id, 'reject'), ['note' => 'Terlambat diputuskan'])
        ->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');
    CorrectionWorld::call($this, $this->c->boss, 'POST', approvalUri($id, 'approve'))->assertStatus(409);

    expect(AuditLog::query()->where('event', 'like', 'attendance_correction.%')->orderBy('id')->pluck('event')->all())
        ->toBe(['attendance_correction.submitted', 'attendance_correction.approved']);
});

test('07 §3.4 approval_corrections: hanya yang boleh diputuskan (tanpa milik sendiri), default pending, terlama dulu, cari & filter', function () {
    $other = $this->c->member($this->c->boss['employee']);
    CorrectionWorld::submit($this, $this->c->staff, ['work_date' => '2026-11-13'])->assertCreated();
    Carbon::setTestNow('2026-11-16 05:05:00');
    CorrectionWorld::submit($this, $other)->assertCreated();
    CorrectionWorld::submit($this, $this->c->boss)->assertCreated(); // milik atasan sendiri
    CorrectionWorld::submit($this, $this->c->member())->assertCreated(); // bukan bawahan

    $list = fn (string $query = '') => CorrectionWorld::call($this, $this->c->boss, 'GET', '/api/v1/approvals/attendance-corrections'.$query)->assertOk();

    expect(array_column(array_column($list()->json('data'), 'employee'), 'id'))->toBe([$this->c->staff['employee']->id, $other['employee']->id])
        ->and($list()->json('meta.listing.state.filters.status'))->toBe(['pending'])
        ->and(count($list('?q='.urlencode($other['employee']->full_name))->json('data')))->toBe(1)
        ->and(array_column($list('?filter[work_date][from]=2026-11-14')->json('data'), 'work_date'))->toBe(['2026-11-16'])
        ->and(array_column($list('?sort=-created_at')->json('data'), 'work_date'))->toBe(['2026-11-16', '2026-11-13']);

    // HRD (scope all) melihat semua kecuali miliknya; pegawai biasa tidak punya izin.
    $hrd = $this->c->phoneFor($this->c->w->s->org->employee(), 'hrd');
    expect(CorrectionWorld::call($this, $hrd, 'GET', '/api/v1/approvals/attendance-corrections')->assertOk()->json('meta.pagination.total'))->toBe(4);
    CorrectionWorld::call($this, $this->c->staff, 'GET', '/api/v1/approvals/attendance-corrections')->assertForbidden();
});
