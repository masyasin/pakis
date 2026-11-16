<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Actions\SaveSalesArea;
use App\Domain\Customer\Listings\SalesAreaListing;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Customer\Support\SalesAreaTree;
use App\Domain\Region\Models\District;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\SalesAreaRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Area penjualan (15 §5 `admin.sales-areas.*`, FR-A12, T-073). Tambah/ubah lewat dialog di halaman
 * daftar, jadi setiap baris membawa data isian + label pilihan terpilih. Tanpa hapus: nonaktif.
 */
class SalesAreaController extends Controller
{
    #[UsesListing(SalesAreaListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $query = SalesArea::query()
            ->with(['parent:id,code,name', 'supervisor:id,full_name,employee_number', 'homeStockLocation:id,code,name', 'districts:code,name'])
            ->withCount('customers');

        $result = ListingQuery::apply($query, new SalesAreaListing, $request)
            ->through(fn (SalesArea $a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'parent_id' => $a->parent_id,
                'parent' => $a->parent !== null ? ['value' => $a->parent->id, 'label' => $a->parent->name, 'hint' => $a->parent->code] : null,
                'depth' => SalesAreaTree::depth($a->id),
                'supervisor_employee_id' => $a->supervisor_employee_id,
                'supervisor' => $a->supervisor !== null ? ['value' => $a->supervisor->id, 'label' => $a->supervisor->full_name, 'hint' => $a->supervisor->employee_number] : null,
                'home_stock_location_id' => $a->home_stock_location_id,
                'home_stock_location' => $a->homeStockLocation !== null ? ['value' => $a->homeStockLocation->id, 'label' => $a->homeStockLocation->name, 'hint' => $a->homeStockLocation->code] : null,
                'district_codes' => $a->districts->pluck('code')->sort()->values()->all(),
                'districts' => $a->districts->sortBy('name')->values()->map(fn (District $d) => ['value' => $d->code, 'label' => $d->name, 'hint' => $d->code])->all(),
                'districts_count' => $a->districts->count(),
                'customers_count' => $a->customers_count,
                'is_active' => $a->is_active,
            ]);

        return Inertia::render('sales-areas/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('sales_areas.manage')],
            'maxDepth' => SalesArea::MAX_DEPTH,
            'maxDistricts' => SalesArea::MAX_DISTRICTS,
        ]);
    }

    public function store(SalesAreaRequest $request, SaveSalesArea $action): RedirectResponse
    {
        Gate::authorize('create', SalesArea::class);
        $area = $action->handle(null, $request->salesAreaData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Area penjualan \"{$area->name}\" ditambahkan."]);

        // Kembali ke daftar dengan filter/halaman yang sama (dialog dibuka dari sana).
        return back();
    }

    public function update(SalesAreaRequest $request, SalesArea $salesArea, SaveSalesArea $action): RedirectResponse
    {
        Gate::authorize('update', $salesArea);
        $area = $action->handle($salesArea, $request->salesAreaData(), self::user($request)->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Area penjualan \"{$area->name}\" disimpan."]);

        // Kembali ke daftar dengan filter/halaman yang sama (dialog dibuka dari sana).
        return back();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
