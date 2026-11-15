<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use Carbon\CarbonInterface;
use Closure;

/**
 * Status harian dasar T-036 (BR-066): telat, pulang cepat, menit kerja, lalu status menurut aturan
 * berurutan. Hanya untuk hari yang sudah ada absennya; status yang butuh "hari ditutup" (`absent`,
 * `no_checkout`) dan `holiday`/`off` datang di resolver T-037.
 *
 * Aturan status = daftar berurutan (yang pertama cocok menang), agar mudah disisipi: T-037 menambah
 * holiday/off/absent/no_checkout di depan, Fase HR T-060 menyisipkan `leave` sebelum `absent`.
 */
class BasicDayStatus implements DayStatusResolver
{
    /** Batas SMALLINT UNSIGNED kolom menit (04 §2.13). */
    private const MAX_MINUTES = 65_535;

    public function resolve(AttendanceDay $day): void
    {
        $shift = $day->shift;
        $start = $day->scheduled_start;
        $end = $day->scheduled_end;
        $in = $day->check_in_at;
        $out = $day->check_out_at;

        $late = 0;
        if ($shift !== null && $start !== null && $in !== null && $in->greaterThan($start->copy()->addMinutes($shift->late_tolerance_min))) {
            $late = self::minutes($start, $in);
        }

        $early = 0;
        $tolerance = (int) setting('attendance.early_leave_tolerance_min');
        if ($end !== null && $out !== null && $out->lessThan($end->copy()->subMinutes($tolerance))) {
            $early = self::minutes($out, $end);
        }

        $day->late_minutes = $late;
        $day->early_leave_minutes = $early;
        $day->work_minutes = $in !== null && $out !== null && $out->greaterThan($in) ? self::minutes($in, $out) : 0;
        $day->status = $this->status($day);
        $day->resolved_at = now();
    }

    /**
     * Aturan berurutan BR-068 langkah (5)–(7) untuk hari yang ada absennya.
     *
     * @return list<Closure(AttendanceDay): ?AttendanceStatus>
     */
    protected function rules(): array
    {
        return [
            fn (AttendanceDay $d) => $d->late_minutes > 0 ? AttendanceStatus::Late : null,
            fn (AttendanceDay $d) => $d->early_leave_minutes > 0 ? AttendanceStatus::EarlyLeave : null,
        ];
    }

    private function status(AttendanceDay $day): AttendanceStatus
    {
        foreach ($this->rules() as $rule) {
            $status = $rule($day);
            if ($status !== null) {
                return $status;
            }
        }

        return AttendanceStatus::Present;
    }

    /** Menit penuh dari `$from` ke `$to` (dibulatkan ke bawah: 7 menit 30 detik = 7). */
    private static function minutes(CarbonInterface $from, CarbonInterface $to): int
    {
        return min(self::MAX_MINUTES, intdiv(max(0, $to->getTimestamp() - $from->getTimestamp()), 60));
    }
}
