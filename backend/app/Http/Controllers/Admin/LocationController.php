<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Organization\Actions\CreateLocation;
use App\Domain\Organization\Actions\DeleteLocation;
use App\Domain\Organization\Actions\UpdateLocation;
use App\Domain\Organization\Listings\LocationListing;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\LocationRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Lokasi (05 §4.5, FR-022, BR-041). Lokasi di luar scope pengguna tidak tampil di daftar dan
 * 404 di route ber-ID (Location::resolveRouteBinding).
 */
class LocationController extends Controller
{
    #[UsesListing(LocationListing::class)]
    public function index(Request $request, LocationScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->apply(Location::query()->with(['type:id,name', 'village.district', 'head:id,full_name']), $user);

        $result = ListingQuery::apply($query, new LocationListing, $request)
            ->through(fn (Location $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'type' => $l->type?->name,
                'village' => $l->village?->name,
                'district' => $l->village?->district?->name,
                'head' => $l->head?->full_name,
                'has_coordinates' => $l->hasCoordinates(),
                'radius_m' => $l->effectiveRadius(),
                'radius_is_default' => $l->attendance_radius_m === null,
                'is_active' => $l->is_active,
            ]);

        return Inertia::render('locations/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('locations.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('locations/form', [...self::formOptions(null), 'location' => null, 'regionLabels' => [], 'headLabel' => null]);
    }

    public function store(LocationRequest $request, CreateLocation $action): RedirectResponse
    {
        $location = $action->handle($request->locationData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Lokasi \"{$location->name}\" ditambahkan."]);

        return to_route('admin.locations.index');
    }

    public function edit(Request $request, Location $location): Response
    {
        Gate::authorize('update', $location);
        $location->load(['village.district.regency.province', 'head:id,full_name,employee_number']);
        $village = $location->village;

        return Inertia::render('locations/form', [
            ...self::formOptions($location),
            // Label wilayah terpilih untuk select pencarian (tanpa ini tampil sebagai kode).
            'regionLabels' => array_values(array_filter([
                $village?->district?->regency?->province ? ['value' => $village->district->regency->province->code, 'label' => $village->district->regency->province->name] : null,
                $village?->district?->regency ? ['value' => $village->district->regency->code, 'label' => $village->district->regency->name] : null,
                $village?->district ? ['value' => $village->district->code, 'label' => $village->district->name] : null,
                $village ? ['value' => $village->code, 'label' => $village->name] : null,
            ])),
            'location' => [
                'id' => $location->id,
                'code' => $location->code,
                'name' => $location->name,
                'location_type_id' => $location->location_type_id,
                'address' => $location->address,
                'village_code' => $location->village_code,
                'district_code' => $location->village?->district_code,
                'regency_code' => $location->village?->district?->regency_code,
                'province_code' => $location->village?->district?->regency?->province_code,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
                'attendance_radius_m' => $location->attendance_radius_m,
                'phone' => $location->phone,
                'head_employee_id' => $location->head_employee_id,
                'is_active' => $location->is_active,
            ],
            // Label kepala lokasi terpilih untuk select pencarian.
            'headLabel' => $location->head !== null
                ? ['value' => $location->head->id, 'label' => $location->head->full_name, 'hint' => $location->head->employee_number]
                : null,
        ]);
    }

    public function update(LocationRequest $request, Location $location, UpdateLocation $action): RedirectResponse
    {
        Gate::authorize('update', $location);
        $location = $action->handle($location, $request->locationData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Lokasi \"{$location->name}\" disimpan."]);

        return to_route('admin.locations.index');
    }

    public function destroy(Request $request, Location $location, DeleteLocation $action): RedirectResponse
    {
        Gate::authorize('delete', $location);
        $action->handle($location, self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Lokasi \"{$location->name}\" dihapus."]);

        return to_route('admin.locations.index');
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'locations', self::user($request));
    }

    /** @return array<string, mixed> */
    private static function formOptions(?Location $location): array
    {
        return [
            // Tipe aktif + tipe yang sedang terpasang (boleh dipertahankan walau sudah nonaktif).
            'types' => ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)
                ->where(fn ($q) => $q->where('is_active', true)->when($location, fn ($o) => $o->orWhere('id', $location?->location_type_id)))
                ->orderBy('sort_order')->get(['id', 'name'])
                ->map(fn (ReferenceItem $t) => ['value' => $t->id, 'label' => $t->name])->values(),
            'defaultRadius' => (int) setting('attendance.default_radius_m'),
            'bounds' => [
                'lat' => [LocationRequest::LAT_MIN, LocationRequest::LAT_MAX],
                'lng' => [LocationRequest::LNG_MIN, LocationRequest::LNG_MAX],
            ],
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
