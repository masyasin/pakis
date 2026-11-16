<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Pricing\Actions\CreatePriceVersion;
use App\Domain\Pricing\Actions\SavePriceList;
use App\Domain\Pricing\Actions\SubmitPriceVersion;
use App\Domain\Pricing\Actions\UpsertPriceItems;
use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Enums\PriceVersionStatus;
use App\Domain\Pricing\Listings\PriceListItemListing;
use App\Domain\Pricing\Listings\PriceListListing;
use App\Domain\Pricing\Models\PriceList;
use App\Domain\Pricing\Models\PriceListItem;
use App\Domain\Pricing\Models\PriceListVersion;
use App\Domain\Pricing\PriceResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\PriceItemsRequest;
use App\Http\Requests\Pricing\PriceListRequest;
use App\Http\Requests\Pricing\PriceVersionRequest;
use App\Http\Requests\Pricing\SubmitPriceVersionRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Daftar harga berversi (15 §5 `admin.price-lists.*`, FR-A19, BR-168, SEC-106). Lihat & ekspor =
 * `prices.view`; tambah/ubah list, versi, item, dan ajukan = `prices.manage` (di rute). Terbit
 * langsung hanya pemegang `prices.publish`; lainnya lewat mesin approval.
 */
class PriceListController extends Controller
{
    #[UsesListing(PriceListListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(PriceList::query()->with(['salesArea:id,name', 'stockLocation:id,name']), new PriceListListing, $request)
            ->through(fn (PriceList $l) => [
                ...self::listRow($l),
                'current_version' => PriceResolver::effectiveVersion($l->id, now())?->version_no,
            ]);

        return Inertia::render('price-lists/index', [
            ...$result->toInertia(),
            'kinds' => PriceListKind::options(),
            'canManage' => self::user($request)->can('prices.manage'),
        ]);
    }

    public function store(PriceListRequest $request, SavePriceList $action): RedirectResponse
    {
        $list = $action->handle(null, $request->validated(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Daftar harga {$list->code} ditambahkan. Buat versi pertamanya di sini."]);

        return to_route('admin.price-lists.show', ['list' => $list]);
    }

    public function update(PriceListRequest $request, PriceList $list, SavePriceList $action): RedirectResponse
    {
        $action->handle($list, $request->validated(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Daftar harga {$list->code} disimpan."]);

        return back();
    }

    public function show(Request $request, PriceList $list): Response
    {
        $list->load(['salesArea:id,name', 'stockLocation:id,name']);
        $current = PriceResolver::effectiveVersion($list->id, now());
        $user = self::user($request);

        return Inertia::render('price-lists/show', [
            'list' => self::listRow($list),
            'currentVersionId' => $current?->id,
            'versions' => PriceListVersion::query()->withCount('items')->where('price_list_id', $list->id)
                ->orderByDesc('version_no')->get()->map(fn (PriceListVersion $v) => self::versionRow($v))->values(),
            'nowLocal' => DisplayTime::toLocal(now())->format('Y-m-d\TH:i'),
            'can' => [
                'manage' => $user->can('prices.manage'),
                'publish' => $user->can('prices.publish'),
                'backdate' => (bool) setting('pricing.allow_backdated_publish') && $user->can('prices.publish'),
            ],
        ]);
    }

    public function storeVersion(PriceVersionRequest $request, PriceList $list, CreatePriceVersion $action): RedirectResponse
    {
        $version = $action->handle($list, $request->validated(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Versi {$version->version_no} dibuat sebagai draf."]);

        return to_route('admin.price-lists.items.index', ['version' => $version]);
    }

    public function submitVersion(SubmitPriceVersionRequest $request, PriceListVersion $version, SubmitPriceVersion $action): RedirectResponse
    {
        Gate::authorize('submit', $version);
        $saved = $action->handle($version, self::user($request), $request->validated('backdate_reason'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === PriceVersionStatus::Published
            ? "Versi {$saved->version_no} terbit."
            : "Versi {$saved->version_no} diajukan untuk persetujuan."]);

        return to_route('admin.price-lists.show', ['list' => $saved->price_list_id]);
    }

    #[UsesListing(PriceListItemListing::class)]
    public function items(Request $request, PriceListVersion $version): Response
    {
        $version->load('priceList');
        $request->query->set('filter', [...(array) $request->query('filter', []), 'version' => (string) $version->id]);
        $result = ListingQuery::apply(
            PriceListItem::query()->with(['product:id,sku,name', 'productUnit.unit:id,name'])->where('price_list_version_id', $version->id),
            new PriceListItemListing,
            $request,
        )->through(fn (PriceListItem $i) => [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'sku' => $i->product->sku,
            'name' => $i->product->name,
            'product_unit_id' => $i->product_unit_id,
            'unit' => $i->productUnit->unitLabel(),
            'min_qty' => $i->min_qty,
            'price' => $i->price,
        ]);
        $user = self::user($request);

        return Inertia::render('price-lists/items', [
            ...$result->toInertia(),
            'list' => self::listRow($version->priceList),
            'version' => self::versionRow($version->loadCount('items')),
            'can' => [
                'edit' => Gate::forUser($user)->allows('updateItems', $version),
                'publish' => $user->can('prices.publish'),
                'backdate' => (bool) setting('pricing.allow_backdated_publish') && $user->can('prices.publish'),
            ],
        ]);
    }

    public function exportItems(Request $request, PriceListVersion $version, StartExport $export): HttpResponse
    {
        // Versi dari rute selalu menjadi filter: job ekspor hanya membawa nilai filter.
        $request->query->set('filter', [...(array) $request->query('filter', []), 'version' => (string) $version->id]);

        return $export->handle($request, 'price_list_items', self::user($request));
    }

    public function upsertItems(PriceItemsRequest $request, PriceListVersion $version, UpsertPriceItems $action): RedirectResponse
    {
        Gate::authorize('updateItems', $version);
        $result = $action->handle($version, $request->rows(), $request->removeIds(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Harga disimpan ({$result['saved']} baris diubah, {$result['removed']} dihapus)."]);

        return back();
    }

    /** @return array<string, mixed> */
    private static function listRow(PriceList $l): array
    {
        return [
            'id' => $l->id,
            'code' => $l->code,
            'name' => $l->name,
            'kind' => $l->kind->value,
            'kind_label' => $l->kind->label(),
            'target' => match ($l->kind) {
                PriceListKind::Area => $l->salesArea?->name,
                PriceListKind::Store => $l->stockLocation?->name,
                default => null,
            },
            'description' => $l->description,
            'is_active' => $l->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private static function versionRow(PriceListVersion $v): array
    {
        return [
            'id' => $v->id,
            'version_no' => $v->version_no,
            'effective_from' => $v->effective_from->toIso8601String(),
            'effective_to' => $v->effective_to?->toIso8601String(),
            'status' => $v->status->value,
            'status_label' => $v->status->label(),
            'note' => $v->note,
            'items_count' => (int) $v->getAttribute('items_count'),
            'published_at' => $v->published_at?->toIso8601String(),
            'approval_request_id' => $v->approval_request_id,
        ];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
