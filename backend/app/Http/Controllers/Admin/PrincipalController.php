<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Purchasing\Actions\SavePrincipal;
use App\Domain\Purchasing\Listings\PrincipalListing;
use App\Domain\Purchasing\Models\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PrincipalRequest;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Prinsipal (15 §5 `admin.principals.*`, FR-A11). Lihat = `suppliers.view`, tambah/ubah =
 * `suppliers.manage` (di rute). Tanpa hapus: dinonaktifkan agar produk & promo lama tetap utuh.
 */
class PrincipalController extends Controller
{
    #[UsesListing(PrincipalListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(Principal::query()->withCount(['suppliers']), new PrincipalListing, $request)
            ->through(fn (Principal $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'contact_name' => $p->contact_name,
                'phone' => $p->phone,
                'email' => $p->email,
                'address' => $p->address,
                'website' => $p->website,
                'is_active' => $p->is_active,
                'suppliers_count' => (int) $p->getAttribute('suppliers_count'),
            ]);

        return Inertia::render('principals/index', [
            ...$result->toInertia(),
            'canManage' => $request->user()?->can('suppliers.manage') ?? false,
        ]);
    }

    public function store(PrincipalRequest $request, SavePrincipal $action): RedirectResponse
    {
        $saved = $action->handle(null, $request->validated(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Prinsipal \"{$saved->name}\" ditambahkan."]);

        return back();
    }

    public function update(PrincipalRequest $request, Principal $principal, SavePrincipal $action): RedirectResponse
    {
        $saved = $action->handle($principal, $request->validated(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Prinsipal \"{$saved->name}\" disimpan."]);

        return back();
    }
}
