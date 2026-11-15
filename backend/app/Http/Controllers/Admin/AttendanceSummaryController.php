<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attendance\Listings\AttendanceDayListing;
use App\Domain\Attendance\Listings\AttendanceSummaryListing;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceSummary;
use App\Domain\Attendance\Support\AttendanceDayScope;
use App\Domain\Employee\Models\Employee;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Rekap bulanan presensi (05 §4.10, FR-039, BR-072, T-039). Klik baris → kalender bulan pegawai
 * (07 §4) lewat `?kalender=<id pegawai>` pada route yang sama; hari-harinya ber-scope sama dengan
 * rekap (AttendanceDayScope), jadi pegawai/hari di luar scope tidak pernah terkirim.
 */
class AttendanceSummaryController extends Controller
{
    #[UsesListing(AttendanceSummaryListing::class)]
    public function index(Request $request, AttendanceDayScope $scope): Response
    {
        $actor = self::actor($request);
        $listing = new AttendanceSummaryListing;
        $names = AttendanceSummaryListing::locationNames();
        $result = ListingQuery::apply($listing->exportQuery($actor), $listing, $request)
            ->through(fn (AttendanceSummary $s) => [
                'employee' => ['id' => $s->employee_id, 'name' => $s->full_name, 'number' => $s->employee_number],
                'location' => AttendanceSummaryListing::locationOf($s, $names),
                'scheduled_days' => (int) $s->scheduled_days,
                'present_days' => (int) $s->present_days,
                'late_count' => (int) $s->late_count,
                'late_minutes_total' => (int) $s->late_minutes_total,
                'early_leave_count' => (int) $s->early_leave_count,
                'no_checkout_days' => (int) $s->no_checkout_days,
                'absent_days' => (int) $s->absent_days,
                'off_days' => (int) $s->off_days,
                'attendance_pct' => $s->attendance_pct === null ? null : (float) $s->attendance_pct,
            ]);
        $inertia = $result->toInertia();
        $month = (string) ($inertia['listing']['state']['filters']->month ?? AttendanceSummaryListing::currentMonth());

        return Inertia::render('attendance/summary', [
            ...$inertia,
            'month' => $month,
            'calendar' => self::calendar($request, $actor, $scope, $month),
            'can' => ['export' => $actor->can('attendance.export')],
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'attendance_summary', self::actor($request));
    }

    /**
     * Kalender bulan satu pegawai (07 §4). Null bila parameter tidak ada/tidak sah, atau pegawai itu
     * tidak punya satu pun hari yang terlihat oleh pelaku di bulan ini (tanpa membedakan keduanya).
     *
     * @return array<string, mixed>|null
     */
    private static function calendar(Request $request, User $actor, AttendanceDayScope $scope, string $month): ?array
    {
        $raw = $request->query('kalender');
        if (! is_string($raw) || preg_match('/^[1-9]\d{0,9}$/', $raw) !== 1) {
            return null;
        }

        [$from, $to] = AttendanceSummaryListing::monthRange($month);
        $days = $scope->apply(AttendanceDay::query(), $actor)
            ->where('employee_id', (int) $raw)
            ->whereBetween('work_date', [$from, $to])
            ->with('shift:id,name')
            ->orderBy('work_date')
            ->get();
        if ($days->isEmpty()) {
            return null;
        }

        $employee = Employee::query()->find((int) $raw, ['id', 'full_name', 'employee_number']);

        return [
            'employee' => ['id' => (int) $raw, 'name' => $employee?->full_name, 'number' => $employee?->employee_number],
            'month' => $month,
            // Tanggal lokal kantor (bukan zona browser) untuk penanda "hari ini" di kalender.
            'today' => DisplayTime::toLocal(now())->toDateString(),
            'days' => array_values($days->map(fn (AttendanceDay $d) => [
                'id' => $d->id,
                'date' => $d->work_date->toDateString(),
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'shift' => $d->shift?->name,
                'check_in' => AttendanceDayListing::clock($d->check_in_at, $d->work_date->toDateString()),
                'check_out' => AttendanceDayListing::clock($d->check_out_at, $d->work_date->toDateString()),
                'late_minutes' => $d->late_minutes,
            ])->all()),
        ];
    }

    private static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
