<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Purchasing\Actions\CancelPurchaseReturn;
use App\Domain\Purchasing\Actions\ClosePurchaseReturn;
use App\Domain\Purchasing\Actions\SavePurchaseReturn;
use App\Domain\Purchasing\Actions\ShipPurchaseReturn;
use App\Domain\Purchasing\Actions\SubmitPurchaseReturn;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Listings\PurchaseReturnListing;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Policies\GoodsReceiptPolicy;
use App\Domain\Purchasing\Resources\PurchaseReturnResource;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\PurchaseReturnRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Retur ke prinsipal (15 §5 `admin.purchase-returns.*` + batal/ubah PA-68, FR-A26). Semua aksi =
 * `purchase_returns.manage` (di rute); scope Stok lokasi stok, di luar scope → 404 (IDOR, 06 §5).
 */
class PurchaseReturnController extends Controller
{
    private const HISTORY_EVENTS = [
        'purchase_return.created' => 'Retur dibuat',
        'purchase_return.updated' => 'Retur diubah',
        'purchase_return.submitted' => 'Diajukan',
        'purchase_return.approved' => 'Disetujui (stok direservasi)',
        'purchase_return.rejected' => 'Ditolak',
        'purchase_return.withdrawn' => 'Pengajuan ditarik',
        'purchase_return.shipped' => 'Dikirim ke prinsipal',
        'purchase_return.closed' => 'Dikonfirmasi prinsipal (ditutup)',
        'purchase_return.cancelled' => 'Dibatalkan',
    ];

    #[UsesListing(PurchaseReturnListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $showCost = $user->can('purchase_orders.view') || $user->can('stock.view_cost');
        $query = $scope->forStock(PurchaseReturn::query()->with(['supplier:id,name', 'stockLocation:id,name', 'reason:id,name'])->withCount('lines'), $user, 'purchase_returns.stock_location_id');
        $result = ListingQuery::apply($query, new PurchaseReturnListing, $request)
            ->through(fn (PurchaseReturn $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'created_at' => $r->created_at?->toIso8601String(),
                'supplier' => $r->supplier->name,
                'stock_location' => $r->stockLocation->name,
                'reason' => $r->reason->name,
                'lines' => (int) $r->getAttribute('lines_count'),
                'total_cost' => $showCost ? $r->total_cost : null,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
            ]);

        return Inertia::render('purchase-returns/index', [...$result->toInertia(), 'showCost' => $showCost]);
    }

    public function create(Request $request): Response
    {
        $user = self::user($request);
        $prefill = null;
        if ($request->filled('gr')) {
            $receipt = GoodsReceipt::query()->find($request->integer('gr'));
            abort_if($receipt === null || ! app(GoodsReceiptPolicy::class)->inScope($user, $receipt), 404);
            abort_unless($receipt->status === GoodsReceiptStatus::Posted, 404);
            $prefill = self::fromReceipt($receipt);
        }

        return Inertia::render('purchase-returns/form', ['return' => null, 'prefill' => $prefill, 'reasons' => self::reasons()]);
    }

    public function store(PurchaseReturnRequest $request, SavePurchaseReturn $action): RedirectResponse
    {
        $saved = $action->handle(null, $request->validated(), $request->lines(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Retur {$saved->number} disimpan sebagai draf."]);

        return to_route('admin.purchase-returns.show', ['ret' => $saved]);
    }

    public function show(Request $request, PurchaseReturn $ret): Response
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $ret), 404);

        return Inertia::render('purchase-returns/show', [
            'purchaseReturn' => (new PurchaseReturnResource($ret))->resolve($request),
            'history' => self::history($ret),
            'can' => [
                'edit' => Gate::forUser($user)->allows('update', $ret),
                'submit' => Gate::forUser($user)->allows('update', $ret),
                'ship' => Gate::forUser($user)->allows('ship', $ret),
                'close' => Gate::forUser($user)->allows('close', $ret),
                'cancel' => Gate::forUser($user)->allows('cancel', $ret),
                'view_receipt' => $ret->goods_receipt_id !== null && $user->can('goods_receipts.view'),
            ],
        ]);
    }

    public function edit(Request $request, PurchaseReturn $ret): Response
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $ret), 404);
        Gate::authorize('update', $ret);

        return Inertia::render('purchase-returns/form', [
            'return' => (new PurchaseReturnResource($ret))->resolve($request),
            'prefill' => null,
            'reasons' => self::reasons(),
        ]);
    }

    public function update(PurchaseReturnRequest $request, PurchaseReturn $ret, SavePurchaseReturn $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $ret), 404);
        Gate::authorize('update', $ret);
        $action->handle($ret, $request->validated(), $request->lines(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Retur {$ret->number} disimpan."]);

        return to_route('admin.purchase-returns.show', ['ret' => $ret]);
    }

    public function submit(Request $request, PurchaseReturn $ret, SubmitPurchaseReturn $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $ret), 404);
        Gate::authorize('update', $ret);
        $saved = $action->handle($ret, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === PurchaseReturnStatus::Approved
            ? "Retur {$saved->number} disetujui; stok sudah direservasi."
            : "Retur {$saved->number} diajukan untuk persetujuan."]);

        return back();
    }

    public function ship(Request $request, PurchaseReturn $ret, ShipPurchaseReturn $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $ret), 404);
        // Status dicek di bawah kunci oleh Action (klik ganda → 409), bukan oleh Policy di sini.
        $data = $request->validate(['serials' => ['nullable', 'array'], 'serials.*' => ['array', 'max:1000'], 'serials.*.*' => ['string', 'max:120']]);
        /** @var array<int, list<string>> $serials */
        $serials = array_map(fn ($list) => array_values(array_map('strval', (array) $list)), (array) ($data['serials'] ?? []));
        $saved = $action->handle($ret, $serials, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Retur {$saved->number} dikirim. Stok sudah berkurang."]);

        return back();
    }

    public function close(Request $request, PurchaseReturn $ret, ClosePurchaseReturn $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $ret), 404);
        Gate::authorize('close', $ret);
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:1000']], ['confirmation.required' => 'Tulis catatan konfirmasi dari prinsipal.']);
        $action->handle($ret, trim((string) $data['confirmation']), $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Retur {$ret->number} ditutup."]);

        return back();
    }

    public function cancel(Request $request, PurchaseReturn $ret, CancelPurchaseReturn $action): RedirectResponse
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $ret), 404);
        Gate::authorize('cancel', $ret);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $action->handle($ret, ($data['reason'] ?? '') === '' ? null : (string) $data['reason'], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Retur {$ret->number} dibatalkan."]);

        return back();
    }

    /** @return list<array{value: int, label: string}> */
    private static function reasons(): array
    {
        return array_values(ReferenceItem::query()->forGroup(ReferenceGroup::PurchaseReturnReason)->where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name'])->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->all());
    }

    /**
     * Isi awal dari penerimaan terposting (`?gr=`): supplier, lokasi, dan batch hasil penerimaan yang
     * masih punya stok tersedia (qty awal = tersedia, maks qty diterima).
     *
     * @return array<string, mixed>
     */
    private static function fromReceipt(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing(['supplier:id,name', 'stockLocation:id,name']);
        $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->whereNotNull('stock_batch_id')->get();
        $batches = StockBatch::query()->with(['product:id,sku,name,base_unit_id', 'product.baseUnit:id,name', 'bin:id,code'])->whereIn('id', $lines->pluck('stock_batch_id'))->get()->keyBy('id');

        return [
            'goods_receipt' => ['id' => $receipt->id, 'number' => $receipt->number],
            'supplier' => ['id' => $receipt->supplier->id, 'name' => $receipt->supplier->name],
            'stock_location' => ['id' => $receipt->stockLocation->id, 'name' => $receipt->stockLocation->name],
            'lines' => array_values($lines->map(function (GoodsReceiptLine $l) use ($batches) {
                $batch = $batches->get($l->stock_batch_id);
                if ($batch === null || ! $batch->available()->isPositive()) {
                    return null;
                }

                return [
                    'product_id' => $batch->product_id,
                    'product_label' => "{$batch->product->sku} · {$batch->product->name}",
                    'stock_batch_id' => $batch->id,
                    'batch_no' => $batch->batch_no,
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'status' => $batch->status->value,
                    'bin' => $batch->bin?->code,
                    'available_base' => (string) $batch->available(),
                    'unit' => $batch->product->baseUnit->name,
                ];
            })->filter()->all()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function history(PurchaseReturn $ret): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', $ret->getMorphClass())->where('auditable_id', $ret->id)
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => $log->occurred_at->toIso8601String(),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event_label' => self::HISTORY_EVENTS[$log->event] ?? $log->event,
                'note' => $log->new_values['confirmation'] ?? $log->new_values['reason'] ?? null,
            ])->values()->all();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
