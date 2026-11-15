<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Notifications\CorrectionDecided;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Attendance\Support\CorrectionTimes;
use App\Domain\Audit\AuditLogger;
use App\Domain\Numbering\NumberGenerator;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Presensi manual oleh admin/atasan (keputusan Mas 16 Nov 2026, BR-069): pegawai tidak bisa
 * presensi dari HP (rusak, hilang, ketinggalan) → pengisi yang memenuhi syarat memutuskan 06 §6
 * terhadap pegawai itu mengisi jam + alasan, dan hasilnya koreksi yang LANGSUNG `approved`
 * (pengisi = pengaju = pemutus). Tidak untuk diri sendiri (SEC-031). Route web + form: T-041.
 */
final class RecordManualAttendance
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly CorrectionAuthority $authority,
        private readonly CorrectionTimes $times,
        private readonly ApplyCorrection $apply,
    ) {}

    /**
     * @param  array{work_date: string, type: CorrectionType, proposed_check_in: ?string, proposed_check_out: ?string, reason_id: int, note: string}  $data
     */
    public function handle(User $actor, int $employeeId, array $data): AttendanceCorrection
    {
        if (CorrectionAuthority::isOwn($actor, $employeeId)) {
            throw new ApiError(ErrorCode::CORRECTION_SELF_APPROVAL);
        }
        $approver = $this->authority->approverFor($employeeId);
        if (! $this->authority->mayDecideFor($actor, $employeeId, $approver)) {
            throw new AuthorizationException;
        }

        $date = $data['work_date'];
        CorrectionGuard::assertDateAllowed($date);
        // Pengajuan pegawai yang masih menunggu diputuskan dulu, agar tidak ada dua versi jam.
        CorrectionGuard::assertNoPending($employeeId, $date);
        $day = AttendanceDay::query()->where('employee_id', $employeeId)->where('work_date', $date)->first();
        $this->times->assertValid($employeeId, $date, $data['type'], $data['proposed_check_in'], $data['proposed_check_out'], $day);

        try {
            $correction = DB::transaction(function () use ($actor, $employeeId, $data, $approver) {
                $correction = new AttendanceCorrection([
                    'employee_id' => $employeeId,
                    'work_date' => $data['work_date'],
                    'type' => $data['type'],
                    'proposed_check_in' => $data['type']->hasCheckIn() ? $data['proposed_check_in'] : null,
                    'proposed_check_out' => $data['type']->hasCheckOut() ? $data['proposed_check_out'] : null,
                    'reason_id' => $data['reason_id'],
                    'note' => $data['note'],
                    'status' => CorrectionStatus::Approved,
                    'approver_employee_id' => $approver,
                    'submitted_by' => $actor->id,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ]);
                $correction->number = $this->numbers->next('attendance_correction', isTaken: fn (string $n) => AttendanceCorrection::query()->where('number', $n)->exists());
                $correction->save();

                $day = $this->apply->apply($correction);
                $this->audit->record('attendance_correction.approved', $correction, $day['old'], [
                    ...SubmitCorrection::auditValues($correction),
                    'manual' => true,
                    ...$day['new'],
                ]);

                return $correction;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (CorrectionGuard::isDuplicatePending($e)) {
                throw new ApiError(ErrorCode::CORRECTION_DUPLICATE_PENDING);
            }
            throw $e;
        }

        $correction->load(['employee', 'reason', 'approver', 'submitter', 'decider']);
        $employeeUser = User::query()->where('employee_id', $employeeId)->where('is_active', true)->get();
        Notification::send($employeeUser, new CorrectionDecided(
            $correction->number,
            $correction->work_date->format('d-m-Y'),
            CorrectionStatus::Approved,
            null,
            manual: true,
        ));

        return $correction;
    }
}
