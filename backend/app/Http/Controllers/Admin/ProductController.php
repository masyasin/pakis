<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Actions\CreateProduct;
use App\Domain\Catalog\Actions\UpdateProduct;
use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Listings\ProductListing;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductRegistration;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Queries\ActiveRegistration;
use App\Domain\Catalog\Support\RegistrationState;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Produk (15 §5 `admin.products.*`, FR-A07–A09, BR-150/151, T-070). Izin dicek middleware rute
 * `can:products.view` / `can:products.manage`. Detail bertab (`?tab=`, 15 §7.1): Data, Satuan &
 * Barcode, Izin Edar (T-071), Harga (T-075), Stok (T-087), Riwayat.
 */
class ProductController extends Controller
{
    /** Event audit yang tampil di tab Riwayat produk. */
    private const HISTORY_EVENTS = [
        'product.created' => 'Produk dibuat',
        'product.updated' => 'Data diubah',
        'product.flags_relaxed' => 'Penanda kategori dilonggarkan',
        'product_unit.created' => 'Satuan ditambah',
        'product_unit.updated' => 'Satuan diubah',
        'product_barcode.added' => 'Barcode ditambah',
        'product_barcode.removed' => 'Barcode dihapus',
        'product_registration.created' => 'Izin edar ditambah',
        'product_registration.updated' => 'Izin edar diubah',
        'product_registration.revoked' => 'Izin edar dicabut',
        'product_registration.expired' => 'Izin edar habis',
    ];

    #[UsesListing(ProductListing::class)]
    public function index(Request $request): Response
    {
        $today = ActiveRegistration::localDate();
        $query = Product::query()->with(['category:id,name,kind', 'baseUnit:id,name'])
            ->withMax(['registrations as valid_registration_until' => fn (Builder $q) => $q
                ->where('status', RegistrationStatus::Active)->where('valid_until', '>=', $today)], 'valid_until')
            ->withExists('registrations as has_registration');
        $result = ListingQuery::apply($query, new ProductListing, $request)
            ->through(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category->name,
                'kind_label' => $p->category->kind->label(),
                'active_ingredient' => $p->active_ingredient,
                'package_size' => $p->package_size,
                'base_unit' => $p->baseUnit->name,
                'is_restricted' => $p->is_restricted,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'photo_url' => $p->photoUrl(),
                'requires_registration' => $p->requires_registration,
                'registration_state' => RegistrationState::of(self::dateOrNull($p->getAttribute('valid_registration_until')), (bool) $p->getAttribute('has_registration')),
            ]);

        return Inertia::render('products/index', [
            ...$result->toInertia(),
            'canManage' => $request->user()?->can('products.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/form', [...self::formOptions(), 'product' => null]);
    }

    public function store(ProductRequest $request, CreateProduct $action): RedirectResponse
    {
        $product = $action->handle($request->validated(), self::photo($request), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk \"{$product->name}\" ({$product->sku}) ditambahkan."]);

        return to_route('admin.products.show', $product);
    }

    public function show(Request $request, Product $product): Response
    {
        $product->load(['category:id,name,kind,requires_batch,requires_expiry,requires_serial,requires_registration', 'baseUnit:id,name', 'formulation:id,name', 'principal:id,name']);
        $units = ProductUnit::query()->with(['unit:id,name,meta', 'barcodes:id,product_unit_id,barcode'])
            ->where('product_id', $product->id)->orderByDesc('is_base')->orderBy('factor')->get();
        $user = self::user($request);
        $today = ActiveRegistration::localDate();
        // Berlaku dulu, lalu terbaru; riwayat perpanjangan ikut tampil.
        $registrations = $product->registrations()->orderByRaw("status = 'active' desc")->orderByDesc('valid_until')->orderByDesc('id')->get();
        $active = ActiveRegistration::for($product);

        return Inertia::render('products/show', [
            'product' => [
                ...self::detail($product),
                'category_flags' => [
                    'tracks_batch' => $product->category->requires_batch,
                    'tracks_expiry' => $product->category->requires_expiry,
                    'tracks_serial' => $product->category->requires_serial,
                    'requires_registration' => $product->category->requires_registration,
                ],
            ],
            'units' => $units->map(fn (ProductUnit $u) => [
                'id' => $u->id,
                'unit_id' => $u->unit_id,
                'unit' => $u->unit->name,
                'factor' => $u->factor,
                'factor_label' => $u->factorLabel(),
                'integer_only' => $u->isIntegerOnly(),
                'is_base' => $u->is_base,
                'is_default_sales' => $u->is_default_sales,
                'is_default_purchase' => $u->is_default_purchase,
                'is_active' => $u->is_active,
                'barcodes' => $u->barcodes->map(fn ($b) => ['id' => $b->id, 'barcode' => $b->barcode])->values(),
            ])->values(),
            'unitOptions' => self::unitOptions(),
            'registrations' => $registrations->map(fn (ProductRegistration $r) => ProductRegistrationController::row($r, $today))->values(),
            'registrationState' => RegistrationState::of($active?->valid_until->toDateString(), $registrations->isNotEmpty()),
            'history' => fn () => self::history($product, $units->map(fn (ProductUnit $u) => $u->id)->all(), $registrations->map(fn (ProductRegistration $r) => $r->id)->all()),
            'can' => ['manage' => $user->can('products.manage'), 'manage_registrations' => $user->can('product_registrations.manage')],
        ]);
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('products/form', [...self::formOptions(), 'product' => self::detail($product->load(['baseUnit:id,name']))]);
    }

    public function update(ProductRequest $request, Product $product, UpdateProduct $action): RedirectResponse
    {
        $product = $action->handle($product, $request->validated(), self::photo($request), $request->boolean('remove_photo'), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Produk \"{$product->name}\" disimpan."]);

        return to_route('admin.products.show', $product);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'products', self::user($request));
    }

    /** @return array<string, mixed> */
    private static function detail(Product $p): array
    {
        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'product_category_id' => $p->product_category_id,
            'category' => $p->category->name ?? null,
            'kind_label' => $p->relationLoaded('category') ? $p->category->kind->label() : null,
            'principal_id' => $p->principal_id,
            'principal' => $p->relationLoaded('principal') ? $p->principal?->name : null,
            'brand' => $p->brand,
            'active_ingredient' => $p->active_ingredient,
            'formulation_id' => $p->formulation_id,
            'formulation' => $p->relationLoaded('formulation') ? $p->formulation?->name : null,
            'package_size' => $p->package_size,
            'base_unit_id' => $p->base_unit_id,
            'base_unit' => $p->baseUnit->name,
            'description' => $p->description,
            'usage_note' => $p->usage_note,
            'tracks_batch' => $p->tracks_batch,
            'tracks_expiry' => $p->tracks_expiry,
            'tracks_serial' => $p->tracks_serial,
            'requires_registration' => $p->requires_registration,
            'is_restricted' => $p->is_restricted,
            'restricted_note' => $p->restricted_note,
            'warranty_months' => $p->warranty_months,
            'min_shelf_life_months' => $p->min_shelf_life_months,
            'is_sellable' => $p->is_sellable,
            'is_purchasable' => $p->is_purchasable,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'photo_url' => $p->photoUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private static function formOptions(): array
    {
        return [
            'categories' => ProductCategory::query()->where('is_active', true)->with('parent:id,name')
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'kind', 'requires_batch', 'requires_expiry', 'requires_serial', 'requires_registration'])
                ->map(fn (ProductCategory $c) => [
                    'value' => $c->id,
                    'label' => $c->parent !== null ? "{$c->parent->name} › {$c->name}" : $c->name,
                    'kind_label' => $c->kind->label(),
                    'flags' => [
                        'tracks_batch' => $c->requires_batch,
                        'tracks_expiry' => $c->requires_expiry,
                        'tracks_serial' => $c->requires_serial,
                        'requires_registration' => $c->requires_registration,
                    ],
                ])->values(),
            'units' => self::unitOptions(),
            'formulations' => ReferenceItem::query()->forGroup(ReferenceGroup::Formulation)->where('is_active', true)
                ->orderBy('sort_order')->get(['id', 'name'])->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->values(),
            'statuses' => ProductStatus::options(),
            'kinds' => CategoryKind::options(),
            'skuMode' => (string) setting('product.sku_mode'),
        ];
    }

    /** @return array<int, array{value: int, label: string, integer_only: bool}> */
    private static function unitOptions(): array
    {
        return ReferenceItem::query()->forGroup(ReferenceGroup::Unit)->where('is_active', true)->orderBy('sort_order')
            ->get(['id', 'name', 'meta'])
            ->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name, 'integer_only' => ($r->meta['integer_only'] ?? false) === true])
            ->values()->all();
    }

    /**
     * Riwayat produk & satuannya (20 terakhir). Isi audit produk master bukan data pribadi.
     *
     * @param  array<int, int>  $unitIds
     * @param  array<int, int>  $registrationIds
     * @return array<int, array<string, mixed>>
     */
    private static function history(Product $product, array $unitIds, array $registrationIds = []): array
    {
        return AuditLog::query()->with('user:id,name')
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $p) => $p->where('auditable_type', $product->getMorphClass())->where('auditable_id', $product->id))
                ->when($unitIds !== [], fn (Builder $o) => $o->orWhere(fn (Builder $u) => $u
                    ->where('auditable_type', (new ProductUnit)->getMorphClass())->whereIn('auditable_id', $unitIds)))
                ->when($registrationIds !== [], fn (Builder $o) => $o->orWhere(fn (Builder $r) => $r
                    ->where('auditable_type', (new ProductRegistration)->getMorphClass())->whereIn('auditable_id', $registrationIds))))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => $log->occurred_at->toIso8601String(),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event_label' => self::HISTORY_EVENTS[$log->event] ?? $log->event,
                'reason' => match ($log->event) {
                    'product.flags_relaxed' => $log->new_values['reason'] ?? null,
                    'product_registration.revoked' => $log->new_values['note'] ?? null,
                    default => null,
                },
            ])->values()->all();
    }

    /** Nilai agregat MAX(DATE) dari driver bisa string atau Carbon; kembalikan Y-m-d atau null. */
    private static function dateOrNull(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private static function photo(Request $request): ?UploadedFile
    {
        $file = $request->file('photo');

        return $file instanceof UploadedFile ? $file : null;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
