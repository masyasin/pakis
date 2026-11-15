<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Employee\Actions\CreateAssignment;
use App\Domain\Employee\Actions\EndAssignment;
use App\Domain\Employee\Actions\MutateEmployee;
use App\Domain\Employee\Actions\UpdateAssignment;
use App\Domain\Employee\Listings\AssignmentListing;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\AssignmentRequest;
use App\Http\Requests\Employee\AssignmentUpdateRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Penugasan & mutasi (05 §4.6, FR-026, BR-051, BR-052). Permission dasar lewat middleware route;
 * pegawai/penugasan di luar scope data sudah 404 lewat binding ber-scope.
 */
class AssignmentController extends Controller
{
    #[UsesListing(AssignmentListing::class)]
    public function index(Request $request): Response
    {
        $actor = self::actor($request);
        $listing = new AssignmentListing;
        $today = DisplayTime::toLocal(now())->toDateString();

        $result = ListingQuery::apply($listing->exportQuery($actor), $listing, $request)
            ->through(fn (Assignment $a) => [
                'id' => $a->id,
                'employee' => ['id' => $a->employee_id, 'name' => $a->employee?->full_name, 'number' => $a->employee?->employee_number],
                'position' => $a->position?->name,
                'department' => $a->position?->department?->name,
                'location' => $a->location?->name,
                'supervisor' => $a->supervisor?->full_name,
                'mode_label' => $a->effectiveAttendanceMode()?->label(),
                'mode_from_position' => $a->attendance_mode === null,
                'is_primary' => $a->is_primary,
                'start_date' => $a->start_date->toDateString(),
                'end_date' => $a->end_date?->toDateString(),
                'running' => $a->isRunning($today),
                'scheduled' => $a->start_date->toDateString() > $today,
            ]);

        return Inertia::render('assignments/index', [
            ...$result->toInertia(),
            'can' => ['export' => $actor->can('assignments.view')],
        ]);
    }

    /** Mutasi (penugasan utama baru, BR-052) atau penugasan tambahan (rangkap, BR-051). */
    public function store(AssignmentRequest $request, Employee $employee, MutateEmployee $mutate, CreateAssignment $create): RedirectResponse
    {
        Gate::authorize('manageAssignments', $employee);
        $data = $request->assignmentData();
        $userId = self::actor($request)->id;

        if ($data['is_primary']) {
            $mutate->handle($employee, $data, $userId);
            $message = "Mutasi {$employee->full_name} disimpan.";
        } else {
            $create->handle($employee, $data, $userId, '');
            $message = "Penugasan tambahan untuk {$employee->full_name} disimpan.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.employees.show', ['employee' => $employee, 'tab' => 'penugasan']);
    }

    public function update(AssignmentUpdateRequest $request, Assignment $assignment, UpdateAssignment $action): RedirectResponse
    {
        Gate::authorize('update', $assignment);
        $action->handle($assignment, $request->assignmentData(), self::actor($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Penugasan diperbarui.']);

        return to_route('admin.employees.show', ['employee' => $assignment->employee_id, 'tab' => 'penugasan']);
    }

    public function end(Request $request, Assignment $assignment, EndAssignment $action): RedirectResponse
    {
        Gate::authorize('end', $assignment);
        $endDate = $request->validate(
            ['end_date' => ['required', 'date_format:Y-m-d']],
            ['end_date.required' => 'Isi tanggal selesai.', 'end_date.date_format' => 'Tanggal selesai tidak valid.'],
        )['end_date'];
        $action->handle($assignment, (string) $endDate, self::actor($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Penugasan tambahan diakhiri.']);

        return to_route('admin.employees.show', ['employee' => $assignment->employee_id, 'tab' => 'penugasan']);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'assignments', self::actor($request));
    }

    private static function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
