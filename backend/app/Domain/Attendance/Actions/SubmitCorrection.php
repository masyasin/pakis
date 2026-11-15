<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Notifications\CorrectionSubmitted;
use App\Domain\Attendance\Support\CorrectionAttachment;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Attendance\Support\CorrectionTimes;
use App\Domain\Audit\AuditLogger;
use App\Domain\Numbering\NumberGenerator;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Pegawai mengajukan koreksi presensinya sendiri dari Pakis Pro (05 §5.7, BR-069, 06 §7.1).
 * Pegawai selalu dari akun pengaju (bukan input), status `pending`, penyetuju = atasan langsung
 * saat diajukan. Nomor dari NumberGenerator di transaksi; notifikasi BR-070 setelah commit.
 */
final class SubmitCorrection
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly CorrectionAuthority $authority,
        private readonly CorrectionTimes $times,
        private readonly CorrectionAttachment $attachments,
    ) {}

    /**
     * @param  array{work_date: string, type: CorrectionType, proposed_check_in: ?string, proposed_check_out: ?string, reason_id: int, note: string}  $data
     */
    public function handle(User $submitter, array $data, ?UploadedFile $attachment): AttendanceCorrection
    {
        $employeeId = $submitter->employee_id === null ? null : (int) $submitter->employee_id;
        if ($employeeId === null) {
            throw new ApiError(ErrorCode::FORBIDDEN);
        }

        $date = $data['work_date'];
        CorrectionGuard::assertDateAllowed($date);
        CorrectionGuard::assertNoPending($employeeId, $date);
        $day = AttendanceDay::query()->where('employee_id', $employeeId)->where('work_date', $date)->first();
        $this->times->assertValid($employeeId, $date, $data['type'], $data['proposed_check_in'], $data['proposed_check_out'], $day);

        $path = $attachment === null ? null : $this->attachments->store($attachment, DisplayTime::toLocal(now()));

        try {
            $correction = DB::transaction(function () use ($submitter, $employeeId, $data, $path) {
                $correction = new AttendanceCorrection([
                    'employee_id' => $employeeId,
                    'work_date' => $data['work_date'],
                    'type' => $data['type'],
                    'proposed_check_in' => $data['type']->hasCheckIn() ? $data['proposed_check_in'] : null,
                    'proposed_check_out' => $data['type']->hasCheckOut() ? $data['proposed_check_out'] : null,
                    'reason_id' => $data['reason_id'],
                    'note' => $data['note'],
                    'attachment_path' => $path,
                    'status' => CorrectionStatus::Pending,
                    'approver_employee_id' => $this->authority->approverFor($employeeId),
                    'submitted_by' => $submitter->id,
                ]);
                $correction->number = $this->numbers->next('attendance_correction', isTaken: fn (string $n) => AttendanceCorrection::query()->where('number', $n)->exists());
                $correction->save();

                $this->audit->record('attendance_correction.submitted', $correction, null, self::auditValues($correction));

                return $correction;
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->attachments->delete($path);
            if (CorrectionGuard::isDuplicatePending($e)) {
                throw new ApiError(ErrorCode::CORRECTION_DUPLICATE_PENDING);
            }
            throw $e;
        } catch (Throwable $e) {
            $this->attachments->delete($path);
            throw $e;
        }

        $correction->load(['employee', 'reason', 'approver', 'submitter']);
        Notification::send($this->authority->recipientsFor($correction), new CorrectionSubmitted(
            $correction->number,
            $correction->employee->full_name,
            $correction->work_date->format('d-m-Y'),
            strtolower($correction->type->label()),
        ));

        return $correction;
    }

    /** @return array<string, mixed> 09 §7.1: tanpa catatan bebas & path lampiran */
    public static function auditValues(AttendanceCorrection $correction): array
    {
        return [
            'number' => $correction->number,
            'employee_id' => $correction->employee_id,
            'work_date' => $correction->work_date->toDateString(),
            'type' => $correction->type->value,
            'proposed_check_in' => AttendanceCorrection::hm($correction->proposed_check_in),
            'proposed_check_out' => AttendanceCorrection::hm($correction->proposed_check_out),
            'reason_id' => $correction->reason_id,
            'has_attachment' => $correction->attachment_path !== null,
            'status' => $correction->status->value,
        ];
    }
}
