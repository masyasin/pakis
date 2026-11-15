<?php

use App\Domain\Attendance\Actions\CancelCorrection;
use App\Domain\Attendance\Actions\RecordManualAttendance;
use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Notifications\CorrectionDecided;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Employee\Events\EmployeeOffboarded;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CorrectionWorld;

/*
 * Presensi manual oleh admin/atasan (keputusan Mas 16 Nov 2026, BR-069, 06 §6) dan pembatalan
 * koreksi saat offboarding (BR-053). Route web + form presensi manual menyusul di T-041.
 * Hari ini = Senin 16 Nov 2026 12:00 WIB; shift PAGI 08:00–16:00.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-16 05:00:00');
    Storage::fake('local');
    Notification::fake();
    $this->c = CorrectionWorld::make($this);
});

afterEach(fn () => Carbon::setTestNow());

/** @return array{work_date: string, type: CorrectionType, proposed_check_in: ?string, proposed_check_out: ?string, reason_id: int, note: string} */
function manualData(array $override = []): array
{
    return [
        'work_date' => '2026-11-16',
        'type' => CorrectionType::Both,
        'proposed_check_in' => '08:00',
        'proposed_check_out' => '16:00',
        'reason_id' => (int) ReferenceItem::query()->forGroup(ReferenceGroup::CorrectionReason)->where('code', 'phone_broken')->value('id'),
        'note' => 'HP rusak, presensi diisi atasan',
        ...$override,
    ];
}

function manualError(Closure $call): ?ErrorCode
{
    try {
        $call();
    } catch (ApiError $e) {
        return $e->errorCode;
    }

    return null;
}

test('BR-069 presensi manual: koreksi langsung approved, hari dikoreksi, audit manual, pegawai diberi tahu', function () {
    $correction = app(RecordManualAttendance::class)->handle($this->c->boss['user'], $this->c->staff['employee']->id, manualData());

    expect($correction->status)->toBe(CorrectionStatus::Approved)
        ->and($correction->number)->toBe('KOR-202611-0001')
        ->and($correction->submitted_by)->toBe($this->c->boss['user']->id)
        ->and($correction->decided_by)->toBe($this->c->boss['user']->id)
        ->and($correction->isManual())->toBeTrue();

    $day = AttendanceDay::query()->sole();
    expect($day->is_corrected)->toBeTrue()
        ->and($day->status)->toBe(AttendanceStatus::Present)
        ->and($day->check_in_at?->toIso8601String())->toBe('2026-11-16T01:00:00+00:00')
        ->and($day->check_out_at?->toIso8601String())->toBe('2026-11-16T09:00:00+00:00');

    $audit = AuditLog::query()->where('event', 'attendance_correction.approved')->sole();
    expect($audit->new_values)->toMatchArray(['manual' => true, 'status' => 'approved', 'check_in_at' => '2026-11-16 08:00']);

    Notification::assertSentTo($this->c->staff['user'], CorrectionDecided::class, fn (CorrectionDecided $n) => $n->title() === 'Presensi Anda diisi manual');
});

test('SEC-031 & 06 §6 presensi manual: bukan untuk diri sendiri; supervisor bukan atasan langsung ditolak; HRD boleh', function () {
    $org = $this->c->w->s->org;
    expect(manualError(fn () => app(RecordManualAttendance::class)->handle($this->c->boss['user'], $this->c->boss['employee']->id, manualData())))
        ->toBe(ErrorCode::CORRECTION_SELF_APPROVAL);

    $otherSupervisor = $this->c->phoneFor($org->employee($org->gudang), 'supervisor');
    expect(fn () => app(RecordManualAttendance::class)->handle($otherSupervisor['user'], $this->c->staff['employee']->id, manualData()))
        ->toThrow(AuthorizationException::class);
    // Pegawai biasa tidak punya attendance_corrections.approve.
    $peer = $this->c->member($this->c->boss['employee']);
    expect(fn () => app(RecordManualAttendance::class)->handle($peer['user'], $this->c->staff['employee']->id, manualData()))
        ->toThrow(AuthorizationException::class);

    $hrd = $org->actor('hrd', $org->employee($org->toko));
    app(RecordManualAttendance::class)->handle($hrd, $this->c->staff['employee']->id, manualData());
    expect(AttendanceCorrection::query()->count())->toBe(1);
});

test('BR-069 presensi manual: batas tanggal, pengajuan pending tanggal sama, dan jendela shift berlaku', function () {
    $action = app(RecordManualAttendance::class);
    $boss = $this->c->boss['user'];
    $staffId = $this->c->staff['employee']->id;

    expect(manualError(fn () => $action->handle($boss, $staffId, manualData(['work_date' => '2026-11-01']))))->toBe(ErrorCode::CORRECTION_NOT_ALLOWED);

    CorrectionWorld::submit($this, $this->c->staff)->assertCreated();
    expect(manualError(fn () => $action->handle($boss, $staffId, manualData())))->toBe(ErrorCode::CORRECTION_DUPLICATE_PENDING);

    expect(fn () => $action->handle($boss, $staffId, manualData(['work_date' => '2026-11-13', 'proposed_check_out' => '23:30'])))
        ->toThrow(ValidationException::class);
});

test('BR-053 offboarding membatalkan koreksi pending pegawai (catatan "Pegawai dinonaktifkan"); yang sudah diputuskan tetap', function () {
    $pendingId = CorrectionWorld::submit($this, $this->c->staff)->assertCreated()->json('data.id');
    $approved = app(RecordManualAttendance::class)->handle($this->c->boss['user'], $this->c->staff['employee']->id, manualData(['work_date' => '2026-11-13']));

    event(new EmployeeOffboarded($this->c->staff['employee']->id, '2026-11-16'));

    $pending = AttendanceCorrection::query()->findOrFail($pendingId);
    expect($pending->status)->toBe(CorrectionStatus::Cancelled)
        ->and($pending->decision_note)->toBe(CancelCorrection::OFFBOARDED_NOTE)
        ->and($approved->fresh()?->status)->toBe(CorrectionStatus::Approved)
        ->and(AuditLog::query()->where('event', 'attendance_correction.cancelled')->count())->toBe(1);
});
