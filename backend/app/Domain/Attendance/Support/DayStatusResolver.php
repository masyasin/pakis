<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\AttendanceDay;

/**
 * Titik pakai perhitungan status harian (BR-066, BR-068). Dipanggil RecordAttendance (T-036)
 * setiap kali `attendance_days` berubah, di dalam transaksinya, SEBELUM disimpan.
 *
 * Implementasi mengisi `late_minutes`, `early_leave_minutes`, `work_minutes`, `status`, dan
 * `resolved_at` dari field hari itu (jadwal, check_in/out). Jangan menyimpan (`save`) di sini.
 * T-036 memakai BasicDayStatus; T-037 mengganti binding di AttendanceServiceProvider dengan
 * resolver lengkap (holiday/off/absent/no_checkout, job harian, AssignmentChanged).
 */
interface DayStatusResolver
{
    public function resolve(AttendanceDay $day): void;
}
