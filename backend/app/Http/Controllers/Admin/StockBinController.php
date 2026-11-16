<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Actions\SaveStockBin;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Listings\StockBinListing;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockBinRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rak per lokasi stok (15 §5 `admin.stock-bins.*`, FR-A04). Form tambah/ubah di dialog halaman
 * daftar. Rak di lokasi stok di luar scope → 404 (StockBin::resolveRouteBinding, query ber-scope).
 */
class StockBinController extends Controller
{
    #[UsesListing(StockBinListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->forStock(StockBin::query(), $user, 'stock_bins.stock_location_id');

        $result = ListingQuery::apply($query, new StockBinListing, $request)
            ->through(fn (StockBin $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
                'zone' => $b->zone->value,
                'zone_label' => $b->zone->label(),
                'is_active' => $b->is_active,
                'is_default_quarantine' => $b->isDefaultQuarantine(),
            ]);

        // Filter wajib: tanpa lokasi stok gudang/toko dalam scope, daftar kosong (bukan semua rak).
        $selectedId = $result->state()['filters']['stock_location_id'] ?? null;
        $selected = is_int($selectedId) ? StockLocation::query()->find($selectedId) : null;

        return Inertia::render('stock-bins/index', [
            ...$result->toInertia(),
            'stockLocation' => $selected !== null ? [
                'id' => $selected->id,
                'code' => $selected->code,
                'name' => $selected->name,
                'type_label' => $selected->type->label(),
                'is_active' => $selected->is_active,
            ] : null,
            'zones' => BinZone::options(),
            'canManage' => $selected !== null && Gate::forUser($user)->allows('manageBins', $selected),
        ]);
    }

    public function store(StockBinRequest $request, DataScope $scope, SaveStockBin $action): RedirectResponse
    {
        $user = self::user($request);
        // Lokasi stok di luar scope diperlakukan tidak ada (SEC-033).
        $location = $scope->forStock(StockLocation::query()->whereKey((int) $request->validated('stock_location_id')), $user, 'stock_locations.id')->first()
            ?? abort(404);

        if (! $location->type->hasBins()) {
            // 15 §4.8: kendaraan & TRANSIT tidak berak (`stock_bin_id` selalu NULL).
            throw ValidationException::withMessages(['stock_location_id' => 'Rak hanya untuk lokasi stok gudang atau toko.']);
        }
        Gate::authorize('manageBins', $location);

        $bin = $action->handle(null, $request->validated(), $user->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Rak {$bin->code} ditambahkan."]);

        return back();
    }

    public function update(StockBinRequest $request, StockBin $bin, SaveStockBin $action): RedirectResponse
    {
        Gate::authorize('update', $bin);
        $bin = $action->handle($bin, $request->validated(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Rak {$bin->code} disimpan."]);

        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
