<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Enums\CorrectionType;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\ScheduleResolver;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Jam usulan koreksi (BR-069): `HH:mm` lokal pada `work_date` → UTC, dan cek jendela shift (BR-060).
 * Shift lintas tengah malam: jam sebelum jam mulai shift = keesokan harinya (pulang 02:00 untuk
 * shift 22:00–06:00). Tanpa shift (libur/tidak dijadwalkan) jam bebas pada tanggal itu.
 */
final class CorrectionTimes
{
    public function __construct(private readonly ScheduleResolver $schedules) {}

    /** Shift efektif pegawai pada tanggal itu (null = tidak ada shift). */
    public function shiftOn(int $employeeId, string $workDate): ?Shift
    {
        $schedule = $this->schedules->forDate($employeeId, $workDate);

        return $schedule->isWorkday() ? $schedule->shift : null;
    }

    /**
     * Shift lintas tengah malam: jam yang jatuh SEBELUM jendela masuk dibuka pada tanggal kerja
     * dibaca keesokan harinya (pulang 06:05 untuk shift 22:00–06:00), sedangkan 21:50 tetap hari
     * itu (masuk lebih awal, jendela dibuka 20:00).
     */
    public static function toUtc(string $workDate, string $hm, ?Shift $shift): CarbonImmutable
    {
        $local = CarbonImmutable::parse("{$workDate} ".substr($hm, 0, 5), DisplayTime::timezone())->utc();
        if ($shift !== null && $shift->crosses_midnight) {
            [$start] = ShiftTimes::on($workDate, $shift);
            if ($local->lessThan($start->subMinutes($shift->checkin_open_min))) {
                return $local->addDay();
            }
        }

        return $local;
    }

    /**
     * Validasi jendela (BR-060) & urutan masuk–pulang. Galat per field (`proposed_check_in`/
     * `proposed_check_out`) agar form Pakis Pro/web bisa menandai isian yang salah.
     *
     * @throws ValidationException
     */
    public function assertValid(int $employeeId, string $workDate, CorrectionType $type, ?string $in, ?string $out, ?AttendanceDay $day): void
    {
        $shift = $this->shiftOn($employeeId, $workDate);
        $inAt = $type->hasCheckIn() && $in !== null ? self::toUtc($workDate, $in, $shift) : null;
        $outAt = $type->hasCheckOut() && $out !== null ? self::toUtc($workDate, $out, $shift) : null;
        $errors = [];

        if ($shift !== null) {
            [$start, $end] = ShiftTimes::on($workDate, $shift);
            $open = $start->subMinutes($shift->checkin_open_min);
            $close = $end->addMinutes($shift->checkout_close_min);

            if ($inAt !== null && ($inAt->lessThan($open) || $inAt->greaterThan($end))) {
                $errors['proposed_check_in'] = 'Jam masuk harus antara '.self::local($open).' dan '.self::local($end).' (shift '.$shift->name.').';
            }
            if ($outAt !== null && ($outAt->lessThan($open) || $outAt->greaterThan($close))) {
                $errors['proposed_check_out'] = 'Jam pulang harus antara '.self::local($open).' dan '.self::local($close).' (shift '.$shift->name.').';
            }
        }

        // Pulang ≥ masuk + jeda minimal: masuk = usulan, atau jam masuk yang sudah tercatat hari itu.
        $reference = $inAt ?? $day?->check_in_at;
        if ($outAt !== null && ! isset($errors['proposed_check_out']) && $reference !== null) {
            $gap = (int) setting('attendance.min_minutes_between_in_out');
            if ($outAt->lessThan(CarbonImmutable::instance($reference)->addMinutes(max($gap, 1)))) {
                $errors['proposed_check_out'] = "Jam pulang minimal {$gap} menit setelah jam masuk.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private static function local(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(DisplayTime::timezone())->format('H:i');
    }
}
