<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Notifications\CorrectionDecided;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Setujui / tolak koreksi (06 §6, §7.1, BR-069). Baris koreksi dikunci lalu statusnya dicek ulang:
 * dua penyetuju yang menekan bersamaan → satu berhasil, yang lain 409 `STATE_CONFLICT`.
 * Milik sendiri → 403 `CORRECTION_SELF_APPROVAL` (SEC-031). Wewenang 06 §6 diperiksa ulang di
 * bawah kunci (binding API sudah menyaring, tetapi atasan bisa berubah di antaranya).
 */
final class DecideCorrection
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CorrectionAuthority $authority,
        private readonly ApplyCorrection $apply,
    ) {}

    public function approve(User $actor, int $correctionId, ?string $note): AttendanceCorrection
    {
        return $this->decide($actor, $correctionId, CorrectionStatus::Approved, $note);
    }

    public function reject(User $actor, int $correctionId, string $note): AttendanceCorrection
    {
        return $this->decide($actor, $correctionId, CorrectionStatus::Rejected, $note);
    }

    private function decide(User $actor, int $correctionId, CorrectionStatus $to, ?string $note): AttendanceCorrection
    {
        $correction = DB::transaction(function () use ($actor, $correctionId, $to, $note) {
            $correction = AttendanceCorrection::query()->lockForUpdate()->find($correctionId);
            if ($correction === null) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            if (CorrectionAuthority::isOwn($actor, $correction->employee_id)) {
                throw new ApiError(ErrorCode::CORRECTION_SELF_APPROVAL);
            }
            if (! $this->authority->mayDecideFor($actor, $correction->employee_id, $correction->approver_employee_id)) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }
            if ($correction->status !== CorrectionStatus::Pending) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $correction->status->value]);
            }

            $correction->status = $to;
            $correction->decided_by = $actor->id;
            $correction->decided_at = now();
            $correction->decision_note = $note === null || trim($note) === '' ? null : trim($note);
            $correction->save();

            $day = $to === CorrectionStatus::Approved ? $this->apply->apply($correction) : ['old' => [], 'new' => []];
            $this->audit->record(
                'attendance_correction.'.$to->value,
                $correction,
                ['status' => CorrectionStatus::Pending->value, ...$day['old']],
                ['status' => $to->value, 'number' => $correction->number, ...$day['new']],
            );

            return $correction;
        });

        $correction->load(['employee', 'reason', 'approver', 'submitter', 'decider']);
        Notification::send($correction->submitter, new CorrectionDecided(
            $correction->number,
            $correction->work_date->format('d-m-Y'),
            $to,
            $correction->decision_note,
        ));

        return $correction;
    }
}
