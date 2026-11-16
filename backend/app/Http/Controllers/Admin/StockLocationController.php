<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Actions\SaveStockLocation;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Listings\StockLocationListing;
use App\Domain\Inventory\Models\StockLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockLocationRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lokasi stok (15 §5 `admin.stock-locations.*`, FR-A03, ADR-107). Lokasi stok di luar dimensi Stok
 * pengguna (15 §6.2) tidak tampil dan 404 di route ber-ID (StockLocation::resolveRouteBinding).
 * Tidak ada hapus (15 §5): lokasi stok dinonaktifkan.
 */
class StockLocationController extends Controller
{
    #[UsesListing(StockLocationListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->forStock(
            StockLocation::query()->with(['location:id,name', 'vehicle:id,plate_no'])->withCount('bins'),
            $user,
            'stock_locations.id',
        );

        $result = ListingQuery::apply($query, new StockLocationListing, $request)
            ->through(fn (StockLocation $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'type' => $s->type->value,
                'type_label' => $s->type->label(),
                'location' => $s->location?->name,
                'vehicle_plate' => $s->vehicle?->plate_no,
                'has_bins' => $s->type->hasBins(),
                'bins_count' => $s->bins_count,
                'is_sellable' => $s->is_sellable,
                'is_system' => $s->is_system,
                'is_active' => $s->is_active,
            ]);

        return Inertia::render('stock-locations/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('stock_locations.manage'), 'viewVehicles' => $user->can('vehicles.view')],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('stock-locations/form', [
            'stockLocation' => null,
            'types' => StockLocationType::options(StockLocationType::manual()),
            'locationLabel' => null,
        ]);
    }

    public function store(StockLocationRequest $request, SaveStockLocation $action): RedirectResponse
    {
        $location = $action->handle(null, $request->stockLocationData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Lokasi stok \"{$location->name}\" ditambahkan dengan rak karantina Q-01."]);

        return to_route('admin.stock-locations.index');
    }

    public function edit(StockLocation $stockLocation): Response
    {
        Gate::authorize('update', $stockLocation);
        $stockLocation->load(['location:id,code,name', 'vehicle:id,plate_no,name']);

        return Inertia::render('stock-locations/form', [
            'stockLocation' => [
                'id' => $stockLocation->id,
                'code' => $stockLocation->code,
                'name' => $stockLocation->name,
                'type' => $stockLocation->type->value,
                'type_label' => $stockLocation->type->label(),
                'location_id' => $stockLocation->location_id,
                'vehicle_id' => $stockLocation->vehicle_id,
                'vehicle_plate' => $stockLocation->vehicle?->plate_no,
                'is_sellable' => $stockLocation->is_sellable,
                'is_active' => $stockLocation->is_active,
            ],
            'types' => StockLocationType::options(StockLocationType::manual()),
            // Label lokasi terpilih untuk select pencarian (tanpa ini tampil sebagai ID).
            'locationLabel' => $stockLocation->location !== null
                ? ['value' => $stockLocation->location->id, 'label' => $stockLocation->location->name, 'hint' => $stockLocation->location->code]
                : null,
        ]);
    }

    public function update(StockLocationRequest $request, StockLocation $stockLocation, SaveStockLocation $action): RedirectResponse
    {
        Gate::authorize('update', $stockLocation);
        $location = $action->handle($stockLocation, $request->stockLocationData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Lokasi stok \"{$location->name}\" disimpan."]);

        return to_route('admin.stock-locations.index');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
