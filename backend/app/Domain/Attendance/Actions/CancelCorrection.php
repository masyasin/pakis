<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Audit\AuditLogger;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan koreksi `pending` (06 §7.1): oleh pengaju sendiri (API), atau sistem saat pegawai
 * dinonaktifkan (BR-053, listener CancelPendingCorrections). Koreksi orang lain → 404.
 */
final class CancelCorrection
{
    public const OFFBOARDED_NOTE = 'Pegawai dinonaktifkan';

    public function __construct(private readonly AuditLogger $audit) {}

    public function byOwner(User $actor, int $correctionId): AttendanceCorrection
    {
        return DB::transaction(function () use ($actor, $correctionId) {
            $correction = AttendanceCorrection::query()->lockForUpdate()->find($correctionId);
            if ($correction === null || $correction->submitted_by !== $actor->id) {
                throw new ApiError(ErrorCode::NOT_FOUND);
            }

            return $this->cancel($correction, null);
        });
    }

    /** Semua `pending` milik pegawai → `cancelled` (BR-053). */
    public function allPendingOf(int $employeeId): int
    {
        return DB::transaction(function () use ($employeeId) {
            $pending = AttendanceCorrection::query()
                ->where('employee_id', $employeeId)
                ->where('status', CorrectionStatus::Pending)
                ->lockForUpdate()
                ->get();

            foreach ($pending as $correction) {
                $this->cancel($correction, self::OFFBOARDED_NOTE);
            }

            return $pending->count();
        });
    }

    private function cancel(AttendanceCorrection $correction, ?string $note): AttendanceCorrection
    {
        if ($correction->status !== CorrectionStatus::Pending) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $correction->status->value]);
        }

        $correction->status = CorrectionStatus::Cancelled;
        $correction->decided_at = now();
        $correction->decision_note = $note;
        $correction->save();

        $this->audit->record('attendance_correction.cancelled', $correction,
            ['status' => CorrectionStatus::Pending->value],
            ['status' => CorrectionStatus::Cancelled->value, 'number' => $correction->number, 'note' => $note]);

        return $correction;
    }
}
