<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Attendance\Actions\CancelFieldTrip;
use App\Domain\Attendance\Actions\CreateFieldTrip;
use App\Domain\Attendance\Actions\UpdateFieldTrip;
use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Listings\FieldTripListing;
use App\Domain\Attendance\Models\FieldTrip;
use App\Domain\Employee\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\FieldTripRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tugas luar kota (05 §4.9, BR-071, T-036A bagian 1). Izin dicek middleware route
 * (`can:field_trips.*`); ubah/batal juga lewat FieldTripPolicy (semua pegawai dalam scope).
 * `{trip}` di luar scope → 404 (FieldTrip::resolveRouteBinding).
 */
class FieldTripController extends Controller
{
    #[UsesListing(FieldTripListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $query = FieldTrip::query()->with('employees:id,full_name,employee_number');
        FieldTrip::constrainToScope($query, $user);

        $result = ListingQuery::apply($query, new FieldTripListing, $request)
            ->through(fn (FieldTrip $t) => [
                ...$t->summary(),
                'can' => [
                    'update' => Gate::forUser($user)->allows('update', $t),
                    'cancel' => Gate::forUser($user)->allows('cancel', $t),
                ],
            ]);

        return Inertia::render('field-trips/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('field_trips.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('field-trips/form', [...self::formOptions(), 'trip' => null, 'employeeLabels' => []]);
    }

    public function store(FieldTripRequest $request, CreateFieldTrip $action): RedirectResponse
    {
        $trip = $action->handle($request->tripData(), $request->employeeIds(), $request->destinations(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Tugas {$trip->number} dibuat dan langsung berlaku."]);

        return to_route('admin.field-trips.index');
    }

    public function edit(FieldTrip $trip): Response
    {
        Gate::authorize('update', $trip);
        $trip->load(['employees:id,full_name,employee_number', 'destinations']);

        return Inertia::render('field-trips/form', [
            ...self::formOptions(),
            'trip' => [
                'id' => $trip->id,
                'number' => $trip->number,
                'start_date' => $trip->start_date->toDateString(),
                'end_date' => $trip->end_date->toDateString(),
                'destination' => $trip->destination,
                'purpose' => $trip->purpose,
                'attendance_rule' => $trip->attendance_rule->value,
                'destinations' => $trip->destinations->map(fn ($d) => [
                    'name' => $d->name,
                    'latitude' => $d->latitude,
                    'longitude' => $d->longitude,
                    'radius_m' => $d->radius_m,
                ])->values()->all(),
                'employee_ids' => $trip->employees->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ],
            // Label pegawai terpilih untuk select pencarian (tanpa ini tampil sebagai ID).
            'employeeLabels' => $trip->employees
                ->map(fn (Employee $e) => ['value' => $e->id, 'label' => $e->full_name, 'hint' => $e->employee_number])
                ->values()->all(),
        ]);
    }

    public function update(FieldTripRequest $request, FieldTrip $trip, UpdateFieldTrip $action): RedirectResponse
    {
        Gate::authorize('update', $trip);
        $trip = $action->handle($trip, $request->tripData(), $request->employeeIds(), $request->destinations(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Tugas {$trip->number} disimpan."]);

        return to_route('admin.field-trips.index');
    }

    public function cancel(Request $request, FieldTrip $trip, CancelFieldTrip $action): RedirectResponse
    {
        Gate::authorize('cancel', $trip);
        $trip = $action->handle($trip, self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Tugas {$trip->number} dibatalkan."]);

        return back(fallback: route('admin.field-trips.index'));
    }

    /** @return array{rules: list<array{value: string, label: string}>, maxDays: int, defaultRadius: int, maxDestinations: int} */
    private static function formOptions(): array
    {
        return [
            'rules' => array_map(fn (FieldTripRule $r) => ['value' => $r->value, 'label' => $r->label()], FieldTripRule::cases()),
            'maxDays' => (int) setting('attendance.field_trip_max_days'),
            'defaultRadius' => (int) setting('attendance.default_radius_m'),
            'maxDestinations' => FieldTrip::MAX_DESTINATIONS,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
