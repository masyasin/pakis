<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\EffectiveSchedule;
use App\Domain\Attendance\Enums\AttendanceType;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\ScheduleResolver;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Penentuan `work_date` sebuah absen (BR-060, BR-063).
 *
 * Kandidat = jadwal efektif hari ini, dan jadwal kemarin bila shift-nya lintas tengah malam
 * (pulang 06.05 milik shift malam kemarin). Jadwal dari ScheduleResolver (T-035), satu panggilan.
 *
 * - Masuk: jendela `start − checkin_open_min .. end`; dicek hari ini lebih dulu.
 * - Pulang: kandidat yang SUDAH absen masuk diutamakan, jendela `check_in + min_minutes_between_in_out
 *   .. end + checkout_close_min`; dicek kemarin (lintas hari) lebih dulu karena pulang shift malam
 *   jatuh di pagi hari berikutnya. Tanpa absen masuk → `ATTENDANCE_NOT_CHECKED_IN`, kecuali
 *   `attendance.allow_checkout_without_checkin`.
 * - Tidak ada shift sama sekali → `ATTENDANCE_NO_SCHEDULE` (atau tanggal lokal tanpa shift bila
 *   `attendance.allow_unscheduled`); ada shift tetapi di luar jendela → `ATTENDANCE_OUTSIDE_WINDOW`.
 */
final class WorkDateResolver
{
    public function __construct(private readonly ScheduleResolver $schedules) {}

    public function forRecord(int $employeeId, AttendanceType $type, CarbonImmutable $at): WorkDateCandidate
    {
        $ctx = $this->context($employeeId, $at);

        return $type === AttendanceType::In ? $this->checkIn($ctx, $at) : $this->checkOut($ctx, $at);
    }

    /**
     * Hari kerja "sekarang" untuk layar beranda (`today`): hari kemarin bila shift malamnya sudah
     * absen masuk, belum pulang, dan jendela pulangnya masih terbuka; selain itu hari ini.
     *
     * @return array{work_date: string, schedule: EffectiveSchedule, day: AttendanceDay|null, next_action: 'in'|'out'|null}
     */
    public function current(int $employeeId, CarbonImmutable $now): array
    {
        $ctx = $this->context($employeeId, $now);
        $workDate = $ctx['today'];
        $yesterday = $ctx['candidates'][$ctx['yesterday']] ?? null;
        $yesterdayDay = $ctx['days']->get($ctx['yesterday']);

        if ($yesterday !== null && $yesterdayDay?->check_in_at !== null && $yesterdayDay->check_out_at === null
            && $now->lessThanOrEqualTo($yesterday->checkoutClose() ?? $now)) {
            $workDate = $ctx['yesterday'];
        }

        $schedule = $ctx['schedule'][$workDate];
        $day = $ctx['days']->get($workDate);
        $candidate = $ctx['candidates'][$workDate] ?? null;
        $canWork = $candidate !== null || (bool) setting('attendance.allow_unscheduled');

        $next = match (true) {
            ! $canWork => null,
            $day?->check_in_at === null => 'in',
            $candidate === null || $now->lessThanOrEqualTo($candidate->checkoutClose() ?? $now) => 'out',
            default => null,
        };

        return ['work_date' => $workDate, 'schedule' => $schedule, 'day' => $day, 'next_action' => $next];
    }

    /** @param array{today: string, yesterday: string, candidates: array<string, WorkDateCandidate>, days: Collection<string, AttendanceDay>} $ctx */
    private function checkIn(array $ctx, CarbonImmutable $at): WorkDateCandidate
    {
        foreach ([$ctx['today'], $ctx['yesterday']] as $date) {
            $c = $ctx['candidates'][$date] ?? null;
            if ($c !== null && $at->betweenIncluded($c->checkinOpen(), $c->scheduledEnd)) {
                return $c;
            }
        }

        return $this->withoutWindow($ctx);
    }

    /** @param array{today: string, yesterday: string, candidates: array<string, WorkDateCandidate>, days: Collection<string, AttendanceDay>} $ctx */
    private function checkOut(array $ctx, CarbonImmutable $at): WorkDateCandidate
    {
        $gap = (int) setting('attendance.min_minutes_between_in_out');
        $order = [$ctx['yesterday'], $ctx['today']];
        $tooEarly = false;

        foreach ($order as $date) {
            $c = $ctx['candidates'][$date] ?? null;
            $checkIn = $ctx['days']->get($date)?->check_in_at;
            if ($c === null || $checkIn === null) {
                continue;
            }
            $from = CarbonImmutable::instance($checkIn)->addMinutes($gap);
            if ($at->betweenIncluded($from, $c->checkoutClose())) {
                return $c;
            }
            $tooEarly = $tooEarly || ($at->lessThan($from) && $at->greaterThanOrEqualTo($checkIn));
        }

        if ($tooEarly) {
            throw new ApiError(ErrorCode::ATTENDANCE_OUTSIDE_WINDOW);
        }

        foreach ($order as $date) {
            $c = $ctx['candidates'][$date] ?? null;
            if ($c !== null && $ctx['days']->get($date)?->check_in_at === null && $at->betweenIncluded($c->checkinOpen(), $c->checkoutClose())) {
                return $this->withoutCheckIn($c);
            }
        }

        if (! isset($ctx['candidates'][$ctx['today']]) && (bool) setting('attendance.allow_unscheduled')) {
            $checkIn = $ctx['days']->get($ctx['today'])?->check_in_at;
            if ($checkIn !== null && $at->lessThan(CarbonImmutable::instance($checkIn)->addMinutes($gap))) {
                throw new ApiError(ErrorCode::ATTENDANCE_OUTSIDE_WINDOW);
            }
            $c = new WorkDateCandidate($ctx['today'], null, null, null);

            return $checkIn === null ? $this->withoutCheckIn($c) : $c;
        }

        return $this->withoutWindow($ctx);
    }

    private function withoutCheckIn(WorkDateCandidate $c): WorkDateCandidate
    {
        if (! (bool) setting('attendance.allow_checkout_without_checkin')) {
            throw new ApiError(ErrorCode::ATTENDANCE_NOT_CHECKED_IN);
        }

        return $c;
    }

    /**
     * Tidak ada jendela yang cocok: tanpa shift hari ini → tanpa jadwal (atau tanggal lokal bila
     * diizinkan); ada shift → di luar jendela.
     *
     * @param  array{today: string, yesterday: string, candidates: array<string, WorkDateCandidate>, days: Collection<string, AttendanceDay>}  $ctx
     */
    private function withoutWindow(array $ctx): WorkDateCandidate
    {
        if (isset($ctx['candidates'][$ctx['today']])) {
            throw new ApiError(ErrorCode::ATTENDANCE_OUTSIDE_WINDOW);
        }

        if ((bool) setting('attendance.allow_unscheduled')) {
            return new WorkDateCandidate($ctx['today'], null, null, null);
        }

        throw new ApiError(ErrorCode::ATTENDANCE_NO_SCHEDULE);
    }

    /**
     * @return array{today: string, yesterday: string, schedule: array<string, EffectiveSchedule>, candidates: array<string, WorkDateCandidate>, days: Collection<string, AttendanceDay>}
     */
    private function context(int $employeeId, CarbonImmutable $at): array
    {
        $tz = DisplayTime::timezone();
        $local = $at->setTimezone($tz);
        $today = $local->toDateString();
        $yesterday = $local->subDay()->toDateString();

        $schedule = $this->schedules->forRange([$employeeId], $yesterday, $today)[$employeeId];

        $candidates = [];
        foreach ([$yesterday, $today] as $date) {
            $effective = $schedule[$date];
            $shift = $effective->shift;
            if (! $effective->isWorkday() || $shift === null) {
                continue;
            }
            // Kemarin hanya relevan bila shift-nya berakhir di hari ini.
            if ($date === $yesterday && ! $shift->crosses_midnight) {
                continue;
            }
            [$start, $end] = ShiftTimes::on($date, $shift);
            $candidates[$date] = new WorkDateCandidate($date, $shift, $start, $end);
        }

        /** @var Collection<string, AttendanceDay> $days */
        $days = AttendanceDay::query()
            ->where('employee_id', $employeeId)
            ->whereIn('work_date', [$yesterday, $today])
            ->get()
            ->keyBy(fn (AttendanceDay $d) => $d->work_date->toDateString());

        return ['today' => $today, 'yesterday' => $yesterday, 'schedule' => $schedule, 'candidates' => $candidates, 'days' => $days];
    }
}
