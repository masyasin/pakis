<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Actions\DeleteScheduleOverride;
use App\Domain\Attendance\Actions\SetScheduleOverride;
use App\Domain\Attendance\Listings\ScheduleOverrideListing;
use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Attendance\Support\ScheduleDates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ScheduleOverrideRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Pengecualian jadwal per tanggal (05 §4.9, FR-032, BR-061/BR-062). Izin dicek middleware route
 * (`can:schedules.*`); ber-scope data lewat pegawainya (06 §5). `{override}` di luar scope → 404.
 */
class ScheduleOverrideController extends Controller
{
    #[UsesListing(ScheduleOverrideListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->apply(ScheduleOverrideListing::baseQuery(), $user, 'schedule_overrides.employee_id');

        $result = ListingQuery::apply($query, new ScheduleOverrideListing, $request)
            ->through(fn (ScheduleOverride $o) => [
                'id' => $o->id,
                'date' => $o->date->toDateString(),
                'employee' => ['id' => $o->employee_id, 'name' => $o->employee?->full_name, 'number' => $o->employee?->employee_number],
                'shift' => $o->shift?->summary(),
                'note' => $o->note,
            ]);

        return Inertia::render('schedule-overrides/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('schedules.manage')],
        ]);
    }

    public function store(ScheduleOverrideRequest $request, SetScheduleOverride $action): RedirectResponse
    {
        $date = $request->overrideDate();
        $summary = $action->handle($request->employeeIds(), $date, $request->shiftId(), $request->note(), self::user($request)->id);

        $label = ScheduleDates::long($date);
        $changed = $summary['created'] + $summary['updated'];
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed === 0
                ? 'Pengecualian jadwal tidak berubah.'
                : "Pengecualian jadwal {$label} disimpan untuk {$changed} pegawai.",
        ]);

        return back(fallback: route('admin.schedule-overrides.index'));
    }

    public function destroy(ScheduleOverride $override, DeleteScheduleOverride $action): RedirectResponse
    {
        $action->handle($override);

        $label = ScheduleDates::long($override->date);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Pengecualian jadwal {$label} dihapus; tanggal itu kembali mengikuti pola."]);

        return back(fallback: route('admin.schedule-overrides.index'));
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'schedule_overrides', self::user($request));
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
