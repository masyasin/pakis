<?php

namespace App\Domain\Attendance\Actions;

use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\ScheduleResolver;
use App\Domain\Attendance\Support\CorrectionTimes;
use App\Domain\Attendance\Support\DaySnapshot;
use App\Domain\Attendance\Support\DayStatusResolver;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Terapkan koreksi yang disetujui ke `attendance_days` (BR-069): jam masuk/pulang diganti nilai
 * usulan, `is_corrected = true`, status dihitung ulang. Log asli (`attendance_logs`, `*_log_id`)
 * TIDAK disentuh: jejak presensi dari HP tetap ada untuk audit. Harus dipanggil di transaksi pemanggil.
 */
final class ApplyCorrection
{
    public function __construct(
        private readonly DayStatusResolver $status,
        private readonly ScheduleResolver $schedules,
    ) {}

    /**
     * @return array{old: array<string, mixed>, new: array<string, mixed>} nilai hari untuk audit
     */
    public function apply(AttendanceCorrection $correction): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ApplyCorrection harus di dalam transaksi.');
        }

        $date = $correction->work_date->toDateString();
        $day = AttendanceDay::query()
            ->where('employee_id', $correction->employee_id)
            ->where('work_date', $date)
            ->lockForUpdate()
            ->first();

        if ($day === null) {
            // Tanggal tanpa baris (pegawai tidak presensi & hari belum ditutup): snapshot sama
            // dengan resolver harian agar rekap tidak membedakan baris buatan koreksi.
            $day = new AttendanceDay(['employee_id' => $correction->employee_id, 'work_date' => $date]);
            DaySnapshot::apply($day, $date, $this->schedules->forDate($correction->employee_id, $date), DaySnapshot::primaryAssignmentOn($correction->employee_id, $date));
        }

        $old = self::values($day);
        $shift = $day->shift;

        if ($correction->type->hasCheckIn() && $correction->proposed_check_in !== null) {
            $day->check_in_at = CorrectionTimes::toUtc($date, $correction->proposed_check_in, $shift);
        }
        if ($correction->type->hasCheckOut() && $correction->proposed_check_out !== null) {
            $day->check_out_at = CorrectionTimes::toUtc($date, $correction->proposed_check_out, $shift);
        }
        $day->is_corrected = true;

        $this->status->resolve($day);
        $day->save();

        return ['old' => $old, 'new' => self::values($day)];
    }

    /** @return array<string, mixed> */
    private static function values(AttendanceDay $day): array
    {
        return [
            'check_in_at' => self::local($day->check_in_at),
            'check_out_at' => self::local($day->check_out_at),
            // Baris baru (belum ada presensi) belum punya status sebelum dihitung → null di nilai lama.
            'day_status' => array_key_exists('status', $day->getAttributes()) ? $day->status->value : null,
            'is_corrected' => (bool) $day->is_corrected,
        ];
    }

    private static function local(?CarbonInterface $moment): ?string
    {
        return $moment === null ? null : DisplayTime::toLocal($moment)->format('Y-m-d H:i');
    }
}
