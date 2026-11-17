<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Pricing\Actions\EndPromotion;
use App\Domain\Pricing\Actions\SavePromotion;
use App\Domain\Pricing\Actions\SubmitPromotion;
use App\Domain\Pricing\Data\PricedLine;
use App\Domain\Pricing\Data\PromotionContext;
use App\Domain\Pricing\Enums\PromotionFunding;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Enums\PromotionType;
use App\Domain\Pricing\Listings\PromotionListing;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Pricing\Support\PromotionCandidates;
use App\Domain\Purchasing\Models\Principal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PromotionRequest;
use App\Http\Requests\Pricing\SimulatePromotionRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Promo (15 §5 `admin.promotions.*`, FR-A20, BR-169). Lihat & simulasi = `promotions.view`;
 * tambah/ubah/ajukan/akhiri = `promotions.manage` (di rute). Halaman ubah juga menjadi detail
 * hanya-baca untuk promo yang bukan draf.
 */
class PromotionController extends Controller
{
    #[UsesListing(PromotionListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(Promotion::query()->with('principal:id,name'), new PromotionListing, $request)
            ->through(fn (Promotion $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'type' => $p->type->value,
                'type_label' => $p->type->label(),
                'starts_at' => $p->starts_at->toIso8601String(),
                'ends_at' => $p->ends_at->toIso8601String(),
                'priority' => $p->priority,
                'is_stackable' => $p->is_stackable,
                'funded_by_label' => $p->funded_by->label().($p->principal !== null ? " · {$p->principal->name}" : ''),
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
            ]);

        return Inertia::render('promotions/index', [
            ...$result->toInertia(),
            'channels' => self::channelOptions(),
            'canManage' => self::user($request)->can('promotions.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('promotions/form', [...self::formOptions($request), 'promotion' => null, 'labels' => []]);
    }

    public function store(PromotionRequest $request, SavePromotion $action): RedirectResponse
    {
        $promo = $action->handle(null, $request->validated(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Promo {$promo->code} disimpan sebagai draf."]);

        return to_route('admin.promotions.edit', ['promo' => $promo]);
    }

    public function edit(Request $request, Promotion $promo): Response
    {
        $promo->load(['targets', 'scopes', 'tiers', 'principal:id,name']);

        return Inertia::render('promotions/form', [
            ...self::formOptions($request),
            'promotion' => self::detail($promo),
            'labels' => self::labels($promo, self::user($request)),
        ]);
    }

    public function update(PromotionRequest $request, Promotion $promo, SavePromotion $action): RedirectResponse
    {
        Gate::authorize('update', $promo);
        $action->handle($promo, $request->validated(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Promo {$promo->code} disimpan."]);

        return back();
    }

    public function submit(Request $request, Promotion $promo, SubmitPromotion $action): RedirectResponse
    {
        Gate::authorize('update', $promo);
        $saved = $action->handle($promo, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === PromotionStatus::Active
            ? "Promo {$saved->code} aktif."
            : "Promo {$saved->code} diajukan untuk persetujuan."]);

        return back();
    }

    public function end(Request $request, Promotion $promo, EndPromotion $action): RedirectResponse
    {
        Gate::authorize('end', $promo);
        $action->handle($promo, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Promo {$promo->code} diakhiri."]);

        return back();
    }

    /**
     * Simulasi harga + promo (BR-169): harga dari PriceResolver, promo dari PromotionEngine dengan
     * promo aktif saat `at`. HANYA membaca: tidak ada data yang ditulis (diuji dengan log query).
     */
    public function simulate(SimulatePromotionRequest $request, PriceResolver $prices, PromotionEngine $engine, DataScope $scope): JsonResponse
    {
        $user = self::user($request);
        $customer = null;
        if ($request->validated('customer_id') !== null) {
            $customerId = (int) $request->validated('customer_id');
            // IDOR: kios di luar scope data pengguna diperlakukan tidak ada.
            abort_unless($scope->allowsCustomer($user, $customerId), 404);
            $customer = Customer::query()->findOrFail($customerId);
        }
        $at = $request->validated('at') !== null
            ? CarbonImmutable::parse((string) $request->validated('at'), DisplayTime::timezone())->utc()
            : CarbonImmutable::now();
        $channel = (string) $request->validated('channel');

        /** @var array<int, array{product_unit_id: int|string, qty: string}> $rows */
        $rows = $request->validated('lines');
        $units = ProductUnit::query()->with(['product:id,sku,name,product_category_id,principal_id', 'unit:id,name'])
            ->whereIn('id', array_column($rows, 'product_unit_id'))->get()->keyBy('id');
        $lines = [];
        $meta = [];
        foreach ($rows as $i => $row) {
            $unit = $units->get((int) $row['product_unit_id']);
            abort_if($unit === null, 404);
            $qty = Qty::of($row['qty']);
            $resolved = $prices->resolve($customer, $unit->product, $unit, $qty, $at, $channel);
            $key = 'L'.($i + 1);
            $lines[] = new PricedLine($key, $unit->product_id, $unit->product->product_category_id, $unit->product->principal_id,
                (string) $qty, (string) $unit->factor, (string) $resolved->price);
            $meta[$key] = ['sku' => $unit->product->sku, 'name' => $unit->product->name, 'unit' => $unit->unitLabel(), 'qty' => (string) $qty,
                'unit_price' => (string) $resolved->price, 'price_source' => $resolved->sourceListKind->label()];
        }

        $context = new PromotionContext($customer?->id, $customer?->price_list_id, $customer?->sales_area_id, $channel, $at);
        $result = $engine->apply($lines, $context, PromotionCandidates::at($at), (string) setting('pricing.rounding'));
        $freeProducts = Product::query()->whereIn('id', array_column($result->bonus, 'product_id'))->pluck('name', 'id');

        return response()->json([
            ...$result->toArray(),
            'lines' => array_map(fn (array $l) => [...$meta[$l['key']], ...$l], $result->lines),
            'bonus' => array_map(fn (array $b) => [...$b, 'name' => $freeProducts[$b['product_id']] ?? '–'], $result->bonus),
        ]);
    }

    /** @return array<string, mixed> */
    private static function formOptions(Request $request): array
    {
        $user = self::user($request);

        return [
            'types' => PromotionType::options(),
            'fundings' => PromotionFunding::options(),
            'channels' => self::channelOptions(),
            'nowLocal' => DisplayTime::toLocal(now())->format('Y-m-d\TH:i'),
            'can' => ['manage' => $user->can('promotions.manage'), 'approve' => $user->can('promotions.approve')],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function channelOptions(): array
    {
        $labels = ['office' => 'Kantor', 'sales' => 'Sales lapangan', 'mitra' => 'Mitra', 'pos' => 'Kasir toko'];

        return array_map(fn (string $c) => ['value' => $c, 'label' => $labels[$c]], Promotion::CHANNELS);
    }

    /** @return array<string, mixed> */
    private static function detail(Promotion $p): array
    {
        $local = fn ($t) => DisplayTime::toLocal($t)->format('Y-m-d\TH:i');

        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'type' => $p->type->value,
            'starts_at' => $local($p->starts_at),
            'ends_at' => $local($p->ends_at),
            'priority' => $p->priority,
            'is_stackable' => $p->is_stackable,
            'channels' => $p->channels,
            'budget_amount' => $p->budget_amount,
            'budget_used' => $p->budget_used,
            'funded_by' => $p->funded_by->value,
            'principal_id' => $p->principal_id,
            'principal_share_pct' => $p->principal_share_pct,
            'description' => $p->description,
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'approval_request_id' => $p->approval_request_id,
            'targets' => $p->targets->map(fn ($t) => ['type' => $t->target_type, 'id' => $t->target_id])->values(),
            'scopes' => $p->scopes->map(fn ($s) => ['type' => $s->scope_type, 'id' => $s->scope_id])->values(),
            'tiers' => $p->tiers->map(fn ($t) => [
                'min_qty_base' => $t->min_qty_base, 'min_amount' => $t->min_amount, 'discount_pct' => $t->discount_pct,
                'discount_amount' => $t->discount_amount, 'bundle_price' => $t->bundle_price,
                'free_product_id' => $t->free_product_id, 'free_qty_base' => $t->free_qty_base,
            ])->values(),
        ];
    }

    /**
     * Label pilihan lookup yang sudah tersimpan (tanpa ini select pencarian menampilkan id).
     *
     * @return array<string, list<array{value: int, label: string}>>
     */
    private static function labels(Promotion $p, User $user): array
    {
        $ids = fn (string $field, string $type) => $p->{$field}->where($field === 'targets' ? 'target_type' : 'scope_type', $type)
            ->pluck($field === 'targets' ? 'target_id' : 'scope_id')->filter()->all();
        $freeIds = $p->tiers->pluck('free_product_id')->filter()->all();
        $opt = fn ($rows, callable $label) => $rows->map(fn ($m) => ['value' => $m->id, 'label' => $label($m)])->values()->all();

        return [
            'products' => $opt(Product::withTrashed()->whereIn('id', [...$ids('targets', 'product'), ...$freeIds])->get(), fn ($m) => "{$m->sku} · {$m->name}"),
            'product_categories' => $opt(ProductCategory::withTrashed()->whereIn('id', $ids('targets', 'category'))->get(), fn ($m) => $m->name),
            'principals' => $opt(Principal::withTrashed()->whereIn('id', [...$ids('targets', 'principal'), $p->principal_id])->get(), fn ($m) => $m->name),
            'price_lists' => $opt(PriceList::query()->whereIn('id', $ids('scopes', 'price_list'))->get(), fn ($m) => "{$m->code} · {$m->name}"),
            'sales_areas' => $opt(SalesArea::withTrashed()->whereIn('id', $ids('scopes', 'sales_area'))->get(), fn ($m) => $m->name),
            // Nama kios hanya untuk kios dalam scope data pengguna (06 §5); di luar scope tampil sebagai id.
            'customers' => $opt(Customer::query()->whereIn('id', $ids('scopes', 'customer'))->get()
                ->filter(fn (Customer $c) => app(DataScope::class)->allowsCustomer($user, $c->id)), fn ($m) => "{$m->code} · {$m->store_name}"),
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
