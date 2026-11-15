<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\Shift;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;

/**
 * Jam mulai/selesai shift pada satu tanggal lokal, dalam UTC (BR-060). Jam shift = jam dinding
 * lokal (`general.timezone`); shift lintas tengah malam selesai keesokan harinya. Satu rumus untuk
 * absen (WorkDateResolver, T-036) dan status harian (AttendanceStatusResolver, T-037).
 */
final class ShiftTimes
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} mulai, selesai (UTC) */
    public static function on(string $date, Shift $shift): array
    {
        $tz = DisplayTime::timezone();
        $start = CarbonImmutable::parse("{$date} {$shift->start_time}", $tz);
        $end = CarbonImmutable::parse("{$date} {$shift->end_time}", $tz);
        if ($shift->crosses_midnight) {
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }
}
