<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Purchasing\Actions\RevealSupplierAccount;
use App\Domain\Purchasing\Actions\SaveSupplier;
use App\Domain\Purchasing\Listings\SupplierListing;
use App\Domain\Purchasing\Models\Supplier;
use App\Domain\Purchasing\Models\SupplierProduct;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Supplier (15 §5 `admin.suppliers.*`, FR-A11, SEC-108). Lihat = `suppliers.view`, ubah & buka
 * rekening = `suppliers.manage`. Nomor rekening TIDAK pernah ada di props halaman mana pun (form
 * edit juga tidak): hanya termasking, nomor lengkap lewat `revealAccount` (JSON, tercatat).
 */
class SupplierController extends Controller
{
    #[UsesListing(SupplierListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(Supplier::query()->with('principal:id,name')->withCount('products'), new SupplierListing, $request)
            ->through(fn (Supplier $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'principal' => $s->principal?->name,
                'tax_id' => $s->tax_id,
                'contact_name' => $s->contact_name,
                'phone' => $s->phone,
                'payment_term_days' => $s->payment_term_days,
                'products_count' => (int) $s->getAttribute('products_count'),
                'is_active' => $s->is_active,
            ]);

        return Inertia::render('suppliers/index', [
            ...$result->toInertia(),
            'canManage' => $request->user()?->can('suppliers.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('suppliers/form', ['supplier' => null, 'labels' => [], 'products' => []]);
    }

    public function store(SupplierRequest $request, SaveSupplier $action): RedirectResponse
    {
        $saved = $action->handle(null, $request->validated(), $request->productRows(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Supplier {$saved->code} \"{$saved->name}\" ditambahkan."]);

        return to_route('admin.suppliers.show', $saved);
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        $supplier->load(['principal:id,name', 'village.district.regency']);

        return Inertia::render('suppliers/show', [
            'supplier' => self::detail($supplier),
            'products' => self::products($supplier),
            'can' => ['manage' => self::user($request)->can('suppliers.manage')],
        ]);
    }

    public function edit(Supplier $supplier): Response
    {
        $supplier->load(['principal:id,name', 'village.district.regency.province']);
        $village = $supplier->village;

        return Inertia::render('suppliers/form', [
            'supplier' => [
                ...self::detail($supplier),
                'district_code' => $village?->district_code,
                'regency_code' => $village?->district?->regency_code,
                'province_code' => $village?->district?->regency?->province_code,
            ],
            // Label terpilih untuk select pencarian (tanpa ini tampil sebagai kode).
            'labels' => array_values(array_filter([
                $village?->district?->regency?->province ? ['value' => $village->district->regency->province->code, 'label' => $village->district->regency->province->name] : null,
                $village?->district?->regency ? ['value' => $village->district->regency->code, 'label' => $village->district->regency->name] : null,
                $village?->district ? ['value' => $village->district->code, 'label' => $village->district->name] : null,
                $village ? ['value' => $village->code, 'label' => $village->name] : null,
            ])),
            'products' => self::products($supplier),
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier, SaveSupplier $action): RedirectResponse
    {
        $saved = $action->handle($supplier, $request->validated(), $request->productRows(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Supplier \"{$saved->name}\" disimpan."]);

        return to_route('admin.suppliers.show', $saved);
    }

    /** Nomor rekening lengkap (SEC-108): JSON `no-store`, tercatat `sensitive_data_viewed`. */
    public function revealAccount(Request $request, Supplier $supplier, RevealSupplierAccount $action): JsonResponse
    {
        Gate::authorize('revealAccount', $supplier);

        return response()->json(['account' => $action->handle(self::user($request), $supplier)])
            ->header('Cache-Control', 'no-store, private, max-age=0');
    }

    /** @return array<string, mixed> */
    private static function detail(Supplier $s): array
    {
        $village = $s->village;

        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'principal_id' => $s->principal_id,
            'principal' => $s->principal?->name,
            'tax_id' => $s->tax_id,
            'contact_name' => $s->contact_name,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'village_code' => $s->village_code,
            'village_label' => $village !== null ? implode(', ', array_filter([$village->name, $village->district?->name, $village->district?->regency?->name])) : null,
            'payment_term_days' => $s->payment_term_days,
            'bank_name' => $s->bank_name,
            // SEC-108: hanya termasking; nomor lengkap lewat revealAccount.
            'bank_account_masked' => $s->maskedAccount(),
            'bank_account_name' => $s->bank_account_name,
            'is_active' => $s->is_active,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function products(Supplier $supplier): array
    {
        return SupplierProduct::query()->with(['product:id,sku,name', 'lastPriceUnit.unit:id,name'])
            ->where('supplier_id', $supplier->id)->get()
            ->sortBy(fn (SupplierProduct $p) => $p->product->name)
            ->map(fn (SupplierProduct $p) => [
                'product_id' => $p->product_id,
                'sku' => $p->product->sku,
                'name' => $p->product->name,
                'supplier_sku' => $p->supplier_sku,
                'lead_time_days' => $p->lead_time_days,
                'is_preferred' => $p->is_preferred,
                'last_price' => $p->last_price,
                'last_price_unit' => $p->lastPriceUnit?->unitLabel(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values()->all();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
