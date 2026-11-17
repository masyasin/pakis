<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Purchasing\Actions\CheckGoodsReceipt;
use App\Domain\Purchasing\Actions\PostGoodsReceipt;
use App\Domain\Purchasing\Actions\SaveGoodsReceipt;
use App\Domain\Purchasing\Actions\SaveReceiptSerials;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Listings\GoodsReceiptListing;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderLine;
use App\Domain\Purchasing\Policies\PurchaseOrderPolicy;
use App\Domain\Purchasing\Resources\GoodsReceiptResource;
use App\Domain\Purchasing\Support\ReceiptLineRules;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\GoodsReceiptRequest;
use App\Http\Requests\Purchasing\ReceiptSerialsRequest;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Penerimaan barang (15 §5 `admin.goods-receipts.*`, FR-A25, BR-103, BR-144, BR-158). Lihat & ekspor =
 * `goods_receipts.view`, buat/ubah/periksa/posting/seri = `goods_receipts.create` (di rute). Scope Stok
 * lokasi penerimaan: daftar dibatasi DataScope, dokumen di luar scope → 404 (IDOR, 06 §5).
 */
class GoodsReceiptController extends Controller
{
    /** Event audit yang tampil di riwayat dokumen. */
    private const HISTORY_EVENTS = [
        'goods_receipt.created' => 'Penerimaan dibuat',
        'goods_receipt.updated' => 'Penerimaan diubah',
        'goods_receipt.serials' => 'Nomor seri diisi',
        'goods_receipt.checked' => 'Diperiksa',
        'goods_receipt.submitted' => 'Diajukan (sisa umur pendek)',
        'goods_receipt.approved' => 'Disetujui',
        'goods_receipt.rejected' => 'Ditolak',
        'goods_receipt.withdrawn' => 'Pengajuan ditarik',
        'goods_receipt.posted' => 'Diposting ke stok',
    ];

    #[UsesListing(GoodsReceiptListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->forStock(GoodsReceipt::query()->with(['supplier:id,name', 'stockLocation:id,name', 'purchaseOrder:id,number']), $user, 'goods_receipts.stock_location_id');
        $result = ListingQuery::apply($query, new GoodsReceiptListing, $request)
            ->through(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'received_at' => $r->received_at->toIso8601String(),
                'supplier' => $r->supplier->name,
                'stock_location' => $r->stockLocation->name,
                'purchase_order' => $r->purchaseOrder?->number,
                'supplier_delivery_no' => $r->supplier_delivery_no,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
            ]);

        return Inertia::render('goods-receipts/index', [
            ...$result->toInertia(),
            'canCreate' => $user->can('goods_receipts.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = self::user($request);
        $order = null;
        if ($request->filled('po')) {
            $order = PurchaseOrder::query()->find($request->integer('po'));
            // PO di luar scope / tidak ada → 404 (IDOR), bukan form kosong.
            abort_if($order === null || ! app(PurchaseOrderPolicy::class)->inScope($user, $order), 404);
        }

        return Inertia::render('goods-receipts/form', [
            ...self::formOptions($user, $order?->stock_location_id),
            'receipt' => null,
            'order' => $order === null ? null : self::orderForReceipt($order, $user),
        ]);
    }

    public function store(GoodsReceiptRequest $request, SaveGoodsReceipt $action): RedirectResponse
    {
        $user = self::user($request);
        $poId = $request->validated('purchase_order_id');
        if ($poId !== null) {
            $order = PurchaseOrder::query()->findOrFail((int) $poId);
            abort_unless(app(PurchaseOrderPolicy::class)->inScope($user, $order), 404);
        }
        $receipt = $action->handle(null, $request->validated(), $request->lines(), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Penerimaan {$receipt->number} disimpan sebagai draf."]);

        return to_route('admin.goods-receipts.show', ['gr' => $receipt]);
    }

    public function show(Request $request, GoodsReceipt $gr): Response
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $gr), 404);
        $twoPerson = (bool) setting('purchasing.receipt_two_person');

        return Inertia::render('goods-receipts/show', [
            'receipt' => (new GoodsReceiptResource($gr))->resolve($request),
            'history' => self::history($gr),
            'rejectReasons' => self::rejectReasons(),
            'twoPerson' => $twoPerson,
            'can' => [
                'edit' => Gate::forUser($user)->allows('update', $gr),
                'check' => Gate::forUser($user)->allows('check', $gr),
                // Tombol posting tetap tampil bagi pemeriksa agar alasan RECEIPT_SAME_PERSON terlihat jelas
                // di layar; servernya yang menolak (SEC-104).
                'post' => Gate::forUser($user)->allows('post', $gr),
                'post_blocked_same_person' => $twoPerson && $gr->checked_by === $user->id,
                'serials' => Gate::forUser($user)->allows('serials', $gr),
                'view_po' => $gr->purchase_order_id !== null && $user->can('purchase_orders.view'),
                // T-081: retur batch hasil penerimaan ini (form retur memeriksa scope lagi).
                'return' => $gr->status === GoodsReceiptStatus::Posted && $user->can('purchase_returns.manage'),
            ],
        ]);
    }

    public function edit(Request $request, GoodsReceipt $gr): Response
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $gr), 404);
        Gate::authorize('update', $gr);
        $order = $gr->purchase_order_id === null ? null : PurchaseOrder::query()->find($gr->purchase_order_id);

        return Inertia::render('goods-receipts/form', [
            ...self::formOptions($user, $gr->stock_location_id),
            'receipt' => (new GoodsReceiptResource($gr))->resolve($request),
            'order' => $order === null ? null : self::orderForReceipt($order, $user),
        ]);
    }

    public function update(GoodsReceiptRequest $request, GoodsReceipt $gr, SaveGoodsReceipt $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $gr), 404);
        Gate::authorize('update', $gr);
        // PO penerimaan tidak bisa diganti lewat ubah (mencegah memindahkan GR ke PO lain di luar scope).
        $data = [...$request->validated(), 'purchase_order_id' => $gr->purchase_order_id];
        $action->handle($gr, $data, $request->lines(), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Penerimaan {$gr->number} disimpan. Periksa ulang sebelum diposting."]);

        return to_route('admin.goods-receipts.show', ['gr' => $gr]);
    }

    public function check(Request $request, GoodsReceipt $gr, CheckGoodsReceipt $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $gr), 404);
        Gate::authorize('check', $gr);
        $checked = $action->handle($gr, $user);
        $short = $checked->lines()->where('line_status', 'short_shelf_life')->count();
        Inertia::flash('toast', ['type' => 'success', 'message' => $short > 0
            ? "Penerimaan {$gr->number} diperiksa. {$short} baris sisa umurnya pendek dan butuh persetujuan saat diposting."
            : "Penerimaan {$gr->number} diperiksa dan siap diposting."]);

        return back();
    }

    public function post(Request $request, GoodsReceipt $gr, PostGoodsReceipt $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $gr), 404);
        // Status dicek di dalam kunci oleh Action (klik ganda → 409 STATE_CONFLICT), bukan oleh Policy di
        // sini: Policy membaca baris tanpa kunci sehingga dua permintaan bersamaan sama-sama lolos.
        abort_unless($user->can('goods_receipts.create'), 403);
        $posted = $action->handle($gr, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $posted->status === GoodsReceiptStatus::Posted
            ? "Penerimaan {$posted->number} diposting. Stok sudah bertambah."
            : "Penerimaan {$posted->number} menunggu persetujuan kepala gudang (sisa umur pendek)."]);

        return back();
    }

    public function serials(ReceiptSerialsRequest $request, GoodsReceipt $gr, GoodsReceiptLine $line, SaveReceiptSerials $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $gr), 404);
        abort_unless($line->goods_receipt_id === $gr->id, 404);
        Gate::authorize('serials', $gr);
        $result = $action->handle($gr, $line, $request->serials(), $request->inputMethod(), $request->file('photo'), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['reset']
            ? "{$result['total']} nomor seri tersimpan. Penerimaan kembali draf: periksa ulang sebelum diposting."
            : "{$result['total']} nomor seri tersimpan untuk baris ini."]);

        return back();
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'goods_receipts', self::user($request));
    }

    /** @return array<string, mixed> */
    private static function formOptions(User $user, ?int $stockLocationId): array
    {
        return [
            'now' => DisplayTime::toLocal(now())->format('Y-m-d\TH:i'),
            'allowWithoutPo' => (bool) setting('purchasing.allow_receipt_without_po') && $user->can('purchase_orders.create'),
            'tolerancePct' => (string) setting('purchasing.over_receipt_tolerance_pct'),
            'minShelfLifeMonths' => (int) setting('inventory.min_shelf_life_months'),
            'rejectReasons' => self::rejectReasons(),
            // Rak lokasi (tanpa karantina: Q-01 dipakai otomatis untuk izin tidak cocok, BR-158(4)).
            'bins' => $stockLocationId === null ? [] : StockBin::query()->where('stock_location_id', $stockLocationId)->where('is_active', true)
                ->where('zone', '!=', BinZone::Quarantine->value)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (StockBin $b) => ['value' => $b->id, 'label' => $b->code.($b->name ? " · {$b->name}" : '')])->values()->all(),
        ];
    }

    /** @return list<array{value: int, label: string}> */
    private static function rejectReasons(): array
    {
        return array_values(ReferenceItem::query()->forGroup(ReferenceGroup::ReceiptRejectReason)->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name'])->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->all());
    }

    /**
     * Ringkas PO untuk form penerimaan: baris + sisa (satuan dasar) setelah GR terposting, dan biaya per
     * satuan dasar hanya bila boleh melihat harga beli (SEC-107).
     *
     * @return array<string, mixed>
     */
    private static function orderForReceipt(PurchaseOrder $order, User $user): array
    {
        $order->loadMissing(['supplier:id,name', 'stockLocation:id,name', 'lines.product:id,sku,name,tracks_batch,tracks_expiry,tracks_serial,requires_registration,base_unit_id', 'lines.product.baseUnit:id,name', 'lines.productUnit.unit:id,name']);
        $showCost = GoodsReceiptResource::canSeeCost($user);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'status_label' => $order->status->label(),
            'open' => $order->status->isOpen(),
            'supplier' => ['id' => $order->supplier->id, 'name' => $order->supplier->name],
            'stock_location' => ['id' => $order->stockLocation->id, 'name' => $order->stockLocation->name],
            'lines' => $order->lines->map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'line_no' => $l->line_no,
                'product_id' => $l->product_id,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'tracks_batch' => $l->product->tracks_batch,
                'tracks_expiry' => $l->product->tracks_expiry,
                'tracks_serial' => $l->product->tracks_serial,
                'requires_registration' => $l->product->requires_registration,
                'product_unit_id' => $l->product_unit_id,
                'unit' => $l->productUnit->unitLabel(),
                'base_unit' => $l->product->baseUnit->name,
                'factor' => $l->productUnit->conversionFactor(),
                'qty' => $l->qty,
                'qty_base' => $l->qty_base,
                'qty_received_base' => $l->qty_received_base,
                'remaining_base' => (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::of($l->qty_base)->minus($l->qty_received_base))->toScale(3),
                'unit_cost' => $showCost ? ReceiptLineRules::unitCostFromPo($l->unit_price, $l->discount_pct, $l->productUnit->conversionFactor()) : null,
            ])->values()->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function history(GoodsReceipt $gr): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', $gr->getMorphClass())->where('auditable_id', $gr->id)
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => $log->occurred_at->toIso8601String(),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event_label' => self::HISTORY_EVENTS[$log->event] ?? $log->event,
            ])->values()->all();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
