<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Models\User;

/**
 * Koreksi presensi (06 §6, §7.1), didaftarkan di AttendanceServiceProvider. Aturan memutuskan ada
 * di CorrectionAuthority (satu pintu dengan API & presensi manual); Policy ini untuk tombol web (T-041).
 */
class AttendanceCorrectionPolicy
{
    public function __construct(private readonly CorrectionAuthority $authority) {}

    public function decide(User $user, AttendanceCorrection $correction): bool
    {
        return $correction->status === CorrectionStatus::Pending
            && ! CorrectionAuthority::isOwn($user, $correction->employee_id)
            && $this->authority->mayDecideFor($user, $correction->employee_id, $correction->approver_employee_id);
    }

    public function cancel(User $user, AttendanceCorrection $correction): bool
    {
        return $correction->status === CorrectionStatus::Pending && $correction->submitted_by === $user->id;
    }
}
