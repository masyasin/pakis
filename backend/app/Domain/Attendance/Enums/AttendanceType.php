<?php

namespace App\Domain\Attendance\Enums;

/** Jenis absen (04 §2.13 `attendance_logs.type`, 05 §5.4 `type`). */
enum AttendanceType: string
{
    case In = 'in';
    case Out = 'out';
}
