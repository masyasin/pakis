<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Illuminate\Database\UniqueConstraintViolationException;

/** Aturan bersama pengajuan koreksi & presensi manual (BR-069). */
final class CorrectionGuard
{
    /** `work_date` ≤ hari ini dan ≥ hari ini − `attendance.correction_max_days` (tanggal lokal). */
    public static function assertDateAllowed(string $workDate): void
    {
        $today = DisplayTime::toLocal(now())->startOfDay();
        $max = (int) setting('attendance.correction_max_days');
        $oldest = $today->subDays($max)->toDateString();

        if ($workDate > $today->toDateString()) {
            throw new ApiError(ErrorCode::CORRECTION_NOT_ALLOWED, ['work_date' => 'Tanggal di masa depan tidak bisa dikoreksi.']);
        }
        if ($workDate < $oldest) {
            throw new ApiError(ErrorCode::CORRECTION_NOT_ALLOWED, ['work_date' => "Koreksi paling lama {$max} hari ke belakang."]);
        }
    }

    /** Maksimal satu `pending` per pegawai-tanggal (cek awal; DB menjaga lewat `att_corr_one_pending`). */
    public static function assertNoPending(int $employeeId, string $workDate): void
    {
        $pending = AttendanceCorrection::query()
            ->where('employee_id', $employeeId)
            ->where('work_date', $workDate)
            ->where('status', CorrectionStatus::Pending)
            ->value('number');

        if ($pending !== null) {
            throw new ApiError(ErrorCode::CORRECTION_DUPLICATE_PENDING, ['number' => $pending]);
        }
    }

    /** Pengajuan bersamaan lolos cek awal: unique `pending_key` menolak yang kedua. */
    public static function isDuplicatePending(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'att_corr_one_pending');
    }
}
