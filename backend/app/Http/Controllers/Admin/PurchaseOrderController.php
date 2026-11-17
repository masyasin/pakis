<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Purchasing\Actions\CancelPurchaseOrder;
use App\Domain\Purchasing\Actions\ClosePurchaseOrder;
use App\Domain\Purchasing\Actions\SavePurchaseOrder;
use App\Domain\Purchasing\Actions\SubmitPurchaseOrder;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Listings\PurchaseOrderListing;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\SupplierProduct;
use App\Domain\Purchasing\Resources\PurchaseOrderResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ClosePurchaseOrderRequest;
use App\Http\Requests\Purchasing\PurchaseOrderRequest;
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
 * Purchase order (15 §5 `admin.purchase-orders.*`, FR-A24, BR-160). Lihat & ekspor =
 * `purchase_orders.view`, buat/ubah/ajukan/batal/tutup = `purchase_orders.create` (di rute). Scope
 * Stok gudang tujuan: daftar dibatasi DataScope, PO di luar scope → 404 (IDOR, 06 §5).
 */
class PurchaseOrderController extends Controller
{
    /** Event audit yang tampil di riwayat dokumen. */
    private const HISTORY_EVENTS = [
        'purchase_order.created' => 'PO dibuat',
        'purchase_order.updated' => 'PO diubah',
        'purchase_order.submitted' => 'Diajukan',
        'purchase_order.approved' => 'Disetujui',
        'purchase_order.rejected' => 'Ditolak',
        'purchase_order.withdrawn' => 'Pengajuan ditarik',
        'purchase_order.cancelled' => 'Dibatalkan',
        'purchase_order.closed' => 'Ditutup',
        'purchase_order.receipt_status' => 'Status penerimaan berubah',
    ];

    #[UsesListing(PurchaseOrderListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->forStock(PurchaseOrder::query()->with(['supplier:id,name', 'stockLocation:id,name']), $user, 'purchase_orders.stock_location_id');
        $today = DisplayTime::toLocal(now())->toDateString();
        $result = ListingQuery::apply($query, new PurchaseOrderListing, $request)
            ->through(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'supplier' => $o->supplier->name,
                'stock_location' => $o->stockLocation->name,
                'order_date' => $o->order_date->toDateString(),
                'expected_date' => $o->expected_date?->toDateString(),
                'grand_total' => $o->grand_total,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'overdue' => $o->status->isOpen() && $o->expected_date !== null && $o->expected_date->toDateString() < $today,
            ]);

        return Inertia::render('purchase-orders/index', [
            ...$result->toInertia(),
            'canCreate' => $user->can('purchase_orders.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('purchase-orders/form', [
            ...self::formOptions($request),
            'order' => null,
        ]);
    }

    public function store(PurchaseOrderRequest $request, SavePurchaseOrder $action): RedirectResponse
    {
        $order = $action->handle(null, $request->validated(), $request->lines(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "PO {$order->number} disimpan sebagai draf."]);

        return to_route('admin.purchase-orders.show', ['po' => $order]);
    }

    public function show(Request $request, PurchaseOrder $po): Response
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $po), 404);

        return Inertia::render('purchase-orders/show', [
            'order' => (new PurchaseOrderResource($po))->resolve($request),
            'history' => self::history($po),
            'can' => [
                'edit' => Gate::forUser($user)->allows('update', $po),
                'submit' => Gate::forUser($user)->allows('update', $po),
                'cancel' => Gate::forUser($user)->allows('cancel', $po),
                'close' => Gate::forUser($user)->allows('close', $po),
                // T-080: terima barang = goods_receipts.create + PO terbuka (form penerimaan mengecek scope lagi).
                'receive' => $user->can('goods_receipts.create') && $po->status->isOpen(),
            ],
            'receipts' => self::receipts($po, $user),
        ]);
    }

    public function edit(Request $request, PurchaseOrder $po): Response
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $po), 404);
        Gate::authorize('update', $po);

        return Inertia::render('purchase-orders/form', [
            ...self::formOptions($request),
            'order' => (new PurchaseOrderResource($po))->resolve($request),
        ]);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $po, SavePurchaseOrder $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $po), 404);
        Gate::authorize('update', $po);
        $action->handle($po, $request->validated(), $request->lines(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "PO {$po->number} disimpan."]);

        return to_route('admin.purchase-orders.show', ['po' => $po]);
    }

    public function submit(Request $request, PurchaseOrder $po, SubmitPurchaseOrder $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $po), 404);
        Gate::authorize('update', $po);
        $saved = $action->handle($po, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === PurchaseOrderStatus::Approved
            ? "PO {$saved->number} disetujui."
            : "PO {$saved->number} diajukan untuk persetujuan."]);

        return back();
    }

    public function cancel(Request $request, PurchaseOrder $po, CancelPurchaseOrder $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $po), 404);
        Gate::authorize('cancel', $po);
        $action->handle($po, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "PO {$po->number} dibatalkan."]);

        return back();
    }

    public function close(ClosePurchaseOrderRequest $request, PurchaseOrder $po, ClosePurchaseOrder $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $po), 404);
        Gate::authorize('close', $po);
        $action->handle($po, (string) $request->validated('reason'), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "PO {$po->number} ditutup."]);

        return back();
    }

    /** Cetak (15 §5 `admin.purchase-orders.pdf`): halaman HTML siap cetak sampai ADR-114/OQ-109. */
    public function pdf(Request $request, PurchaseOrder $po): Response
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $po), 404);

        return Inertia::render('purchase-orders/print', [
            'order' => (new PurchaseOrderResource($po))->resolve($request),
            'company' => setting('general.company_name'),
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'purchase_orders', self::user($request));
    }

    /** @return array<string, mixed> */
    private static function formOptions(Request $request): array
    {
        $supplierId = $request->integer('supplier_id');

        return [
            'today' => DisplayTime::toLocal(now())->toDateString(),
            'defaultTermDays' => (int) setting('purchasing.default_payment_term_days'),
            // Saran harga: harga beli terakhir & lead time per produk dari supplier terpilih (partial reload).
            'lastPrices' => Inertia::optional(fn () => $supplierId <= 0 ? (object) [] : (object) SupplierProduct::query()
                ->with('lastPriceUnit.unit:id,name')
                ->where('supplier_id', $supplierId)->get(['product_id', 'last_price', 'last_price_unit_id', 'lead_time_days'])
                ->mapWithKeys(fn (SupplierProduct $p) => [$p->product_id => [
                    'last_price' => $p->last_price,
                    'last_price_unit_id' => $p->last_price_unit_id,
                    'last_price_unit' => $p->lastPriceUnit?->unitLabel(),
                    'lead_time_days' => $p->lead_time_days,
                ]])->all()),
        ];
    }

    /**
     * Penerimaan atas PO ini (T-080), terbaru dulu. Tautan hanya bila pengguna boleh membuka penerimaan.
     *
     * @return list<array<string, mixed>>
     */
    private static function receipts(PurchaseOrder $po, User $user): array
    {
        $canView = $user->can('goods_receipts.view');

        return array_values(GoodsReceipt::query()->where('purchase_order_id', $po->id)->orderByDesc('received_at')->orderByDesc('id')->limit(50)->get()
            ->map(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'date' => $r->businessDate(),
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'url' => $canView ? '/penerimaan/'.$r->id : null,
            ])->all());
    }

    /** @return array<int, array<string, mixed>> */
    private static function history(PurchaseOrder $po): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', $po->getMorphClass())->where('auditable_id', $po->id)
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => $log->occurred_at->toIso8601String(),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event_label' => self::HISTORY_EVENTS[$log->event] ?? $log->event,
                'reason' => $log->event === 'purchase_order.closed' ? ($log->new_values['close_reason'] ?? null) : null,
            ])->values()->all();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
