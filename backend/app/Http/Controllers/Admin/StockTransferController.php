<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Actions\CancelStockTransfer;
use App\Domain\Inventory\Actions\ReceiveStockTransfer;
use App\Domain\Inventory\Actions\SaveStockTransfer;
use App\Domain\Inventory\Actions\ShipStockTransfer;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Listings\StockTransferListing;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Policies\StockTransferPolicy;
use App\Domain\Inventory\Resources\StockTransferResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ReceiveStockTransferRequest;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mutasi antar lokasi (15 §5 `admin.stock-transfers.*`, FR-A27, BR-161). Lihat = `stock_transfers.view`
 * dengan lokasi asal ATAU tujuan dalam scope Stok; di luar scope → 404 (IDOR, 06 §5). Buat/ubah/
 * kirim/batal = `stock_transfers.create` di lokasi asal; terima = `stock_transfers.receive` di lokasi
 * tujuan (middleware rute + Policy).
 */
class StockTransferController extends Controller
{
    /** Event audit yang tampil di riwayat dokumen. */
    private const HISTORY_EVENTS = [
        'stock_transfer.created' => 'Mutasi dibuat',
        'stock_transfer.updated' => 'Mutasi diubah',
        'stock_transfer.shipped' => 'Dikirim',
        'stock_transfer.received' => 'Diterima',
        'stock_transfer.recounted' => 'Dihitung ulang',
        'stock_transfer.variance_approved' => 'Selisih disetujui',
        'stock_transfer.variance_rejected' => 'Selisih ditolak, hitung ulang',
        'stock_transfer.variance_withdrawn' => 'Pengajuan selisih ditarik',
        'stock_transfer.cancelled' => 'Dibatalkan',
    ];

    public function __construct(private readonly StockTransferPolicy $policy) {}

    #[UsesListing(StockTransferListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $query = $this->policy->visible(StockTransfer::query()->with(['fromLocation:id,name', 'toLocation:id,name'])->withCount('lines'), $user);
        $result = ListingQuery::apply($query, new StockTransferListing, $request)
            ->through(fn (StockTransfer $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'from_location' => $t->fromLocation->name,
                'to_location' => $t->toLocation->name,
                'lines_count' => (int) $t->getAttribute('lines_count'),
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'created_at' => $t->created_at?->toIso8601String(),
                'shipped_at' => $t->shipped_at?->toIso8601String(),
                'received_at' => $t->received_at?->toIso8601String(),
                'overdue' => $t->isOverdue(),
            ]);

        return Inertia::render('stock-transfers/index', [
            ...$result->toInertia(),
            'canCreate' => $user->can('stock_transfers.create'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('stock-transfers/form', ['transfer' => null]);
    }

    public function store(StockTransferRequest $request, SaveStockTransfer $action): RedirectResponse
    {
        $transfer = $action->handle(null, $request->document(), $request->lines(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Mutasi {$transfer->number} disimpan sebagai draf."]);

        return to_route('admin.stock-transfers.show', ['transfer' => $transfer]);
    }

    public function show(Request $request, StockTransfer $transfer): Response
    {
        $user = self::user($request);
        abort_unless(Gate::forUser($user)->allows('view', $transfer), 404);
        $gate = Gate::forUser($user);

        return Inertia::render('stock-transfers/show', [
            'transfer' => (new StockTransferResource($transfer))->resolve($request),
            'history' => self::history($transfer),
            'can' => [
                'edit' => $gate->allows('update', $transfer),
                'ship' => $gate->allows('ship', $transfer),
                'cancel' => $gate->allows('cancel', $transfer),
                'receive' => $gate->allows('receive', $transfer),
            ],
        ]);
    }

    public function edit(Request $request, StockTransfer $transfer): Response
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $transfer), 404);
        Gate::authorize('update', $transfer);

        return Inertia::render('stock-transfers/form', [
            'transfer' => (new StockTransferResource($transfer))->resolve($request),
        ]);
    }

    public function update(StockTransferRequest $request, StockTransfer $transfer, SaveStockTransfer $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $transfer), 404);
        Gate::authorize('update', $transfer);
        $action->handle($transfer, $request->document(), $request->lines(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Mutasi {$transfer->number} disimpan."]);

        return to_route('admin.stock-transfers.show', ['transfer' => $transfer]);
    }

    public function ship(Request $request, StockTransfer $transfer, ShipStockTransfer $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $transfer), 404);
        Gate::authorize('ship', $transfer);
        $action->handle($transfer, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Mutasi {$transfer->number} dikirim. Stok kini dalam perjalanan."]);

        return back();
    }

    public function receive(ReceiveStockTransferRequest $request, StockTransfer $transfer, ReceiveStockTransfer $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $transfer), 404);
        Gate::authorize('receive', $transfer);
        $saved = $action->handle($transfer, $request->lines(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => match ($saved->status) {
            StockTransferStatus::Received => "Mutasi {$saved->number} diterima lengkap.",
            StockTransferStatus::Closed => "Mutasi {$saved->number} diterima; selisih langsung disetujui.",
            default => "Mutasi {$saved->number} diterima dengan selisih. Menunggu persetujuan selisih.",
        }]);

        return back();
    }

    public function cancel(Request $request, StockTransfer $transfer, CancelStockTransfer $action): RedirectResponse
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $transfer), 404);
        Gate::authorize('cancel', $transfer);
        $action->handle($transfer, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Mutasi {$transfer->number} dibatalkan."]);

        return back();
    }

    /** @return array<int, array<string, mixed>> */
    private static function history(StockTransfer $transfer): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', $transfer->getMorphClass())->where('auditable_id', $transfer->id)
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(30)->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'occurred_at' => $log->occurred_at->toIso8601String(),
                'actor' => $log->user->name ?? $log->actor_label ?? 'sistem',
                'event_label' => self::HISTORY_EVENTS[$log->event] ?? $log->event,
                'reason' => null,
            ])->values()->all();
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
