<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Fleet\Actions\SaveVehicle;
use App\Domain\Fleet\Enums\VehicleType;
use App\Domain\Fleet\Listings\VehicleListing;
use App\Domain\Fleet\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\VehicleRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kendaraan minimal (15 §5 `admin.vehicles.*`, FR-A05). Centang canvas membuat/mengaktifkan lokasi
 * stok `vehicle` (SaveVehicle). Kendaraan di luar scope lokasi asal → 404 (route binding ber-scope).
 */
class VehicleController extends Controller
{
    #[UsesListing(VehicleListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->forStockByLocation(
            Vehicle::query()->with(['homeLocation:id,name', 'stockLocation:id,vehicle_id,code,is_active']),
            $user,
            'vehicles.home_location_id',
        );

        $result = ListingQuery::apply($query, new VehicleListing, $request)
            ->through(fn (Vehicle $v) => [
                'id' => $v->id,
                'plate_no' => $v->plate_no,
                'name' => $v->name,
                'vehicle_type' => $v->vehicle_type->value,
                'vehicle_type_label' => $v->vehicle_type->label(),
                'is_canvas' => $v->is_canvas,
                'home_location' => $v->homeLocation?->name,
                'stock_location_code' => $v->stockLocation?->is_active ? $v->stockLocation->code : null,
                'is_active' => $v->is_active,
            ]);

        return Inertia::render('vehicles/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('vehicles.manage')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('vehicles/form', ['vehicle' => null, 'types' => VehicleType::options(), 'homeLabel' => null]);
    }

    public function store(VehicleRequest $request, SaveVehicle $action): RedirectResponse
    {
        $vehicle = $action->handle(null, $request->vehicleData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => $vehicle->is_canvas
            ? "Kendaraan {$vehicle->plate_no} ditambahkan beserta lokasi stok canvasnya."
            : "Kendaraan {$vehicle->plate_no} ditambahkan."]);

        return to_route('admin.vehicles.index');
    }

    public function edit(Vehicle $vehicle): Response
    {
        Gate::authorize('update', $vehicle);
        $vehicle->load(['homeLocation:id,code,name', 'stockLocation:id,vehicle_id,code,is_active']);

        return Inertia::render('vehicles/form', [
            'vehicle' => [
                'id' => $vehicle->id,
                'plate_no' => $vehicle->plate_no,
                'name' => $vehicle->name,
                'vehicle_type' => $vehicle->vehicle_type->value,
                'is_canvas' => $vehicle->is_canvas,
                'home_location_id' => $vehicle->home_location_id,
                'is_active' => $vehicle->is_active,
                'stock_location_code' => $vehicle->stockLocation?->code,
            ],
            'types' => VehicleType::options(),
            'homeLabel' => $vehicle->homeLocation !== null
                ? ['value' => $vehicle->homeLocation->id, 'label' => $vehicle->homeLocation->name, 'hint' => $vehicle->homeLocation->code]
                : null,
        ]);
    }

    public function update(VehicleRequest $request, Vehicle $vehicle, SaveVehicle $action): RedirectResponse
    {
        Gate::authorize('update', $vehicle);
        $vehicle = $action->handle($vehicle, $request->vehicleData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Kendaraan {$vehicle->plate_no} disimpan."]);

        return to_route('admin.vehicles.index');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
