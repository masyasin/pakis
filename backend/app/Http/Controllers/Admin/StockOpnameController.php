<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Actions\AddFoundBatch;
use App\Domain\Inventory\Actions\CancelStockOpname;
use App\Domain\Inventory\Actions\CreateStockOpname;
use App\Domain\Inventory\Actions\FinishCounting;
use App\Domain\Inventory\Actions\RecordCount;
use App\Domain\Inventory\Actions\RecordRecount;
use App\Domain\Inventory\Actions\SetOpnameVarianceReason;
use App\Domain\Inventory\Actions\StartStockOpname;
use App\Domain\Inventory\Actions\SubmitStockOpname;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Listings\StockOpnameLineListing;
use App\Domain\Inventory\Listings\StockOpnameListing;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Domain\Inventory\Resources\StockOpnameLineResource;
use App\Domain\Inventory\Support\OpnameVariance;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\FoundBatchRequest;
use App\Http\Requests\Inventory\OpnameLineRequest;
use App\Http\Requests\Inventory\StockOpnameRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock opname (15 §5 `admin.stock-opnames.*`, FR-A28, BR-162, SEC-103). Lihat = `stock_opnames.view`
 * dengan lokasi dalam scope Stok (di luar → 404, IDOR 06 §5); semua aksi = `stock_opnames.manage`.
 *
 * Pemetaan rute 15 §5 ke transisi 15 §6.6 (PA-67): `start` "/mulai" = mulai (snapshot);
 * `submit` "/ajukan" = selesai hitung (counting → review); `post` "/posting" = ajukan posting
 * (tanpa selisih → posted; ada selisih → pending_approval). `lines.store` (POST "/baris") = batch
 * temuan, melengkapi 15 §5 (BR-162(7)).
 */
class StockOpnameController extends Controller
{
    private const HISTORY_EVENTS = [
        'stock_opname.created' => 'Opname dibuat',
        'stock_opname.started' => 'Mulai dihitung (stok dibekukan)',
        'stock_opname.count_changed' => 'Hitungan diubah',
        'stock_opname.recounted' => 'Dihitung ulang',
        'stock_opname.found_batch' => 'Batch temuan ditambahkan',
        'stock_opname.counted' => 'Selesai dihitung',
        'stock_opname.submitted' => 'Diajukan untuk persetujuan selisih',
        'stock_opname.variance_rejected' => 'Selisih ditolak, hitung ulang',
        'stock_opname.variance_withdrawn' => 'Pengajuan ditarik',
        'stock_opname.posted' => 'Diposting',
        'stock_opname.cancelled' => 'Dibatalkan',
    ];

    #[UsesListing(StockOpnameListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $user = self::user($request);
        $query = $scope->forStock(StockOpname::query()->with('stockLocation:id,name')->withCount('lines'), $user, 'stock_opnames.stock_location_id');
        $result = ListingQuery::apply($query, new StockOpnameListing, $request)
            ->through(fn (StockOpname $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'location' => $o->stockLocation->name,
                'scope' => $o->scope->value,
                'scope_label' => $o->scope->label(),
                'blind_count' => $o->blind_count,
                'lines_count' => (int) $o->getAttribute('lines_count'),
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'created_at' => $o->created_at?->toIso8601String(),
                'posted_at' => $o->posted_at?->toIso8601String(),
            ]);

        return Inertia::render('stock-opnames/index', [
            ...$result->toInertia(),
            'canCreate' => $user->can('stock_opnames.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('stock-opnames/form', [
            'scopes' => StockOpnameScope::options(),
            'blindDefault' => (bool) setting('inventory.opname_blind_count_default'),
        ]);
    }

    public function store(StockOpnameRequest $request, CreateStockOpname $action): RedirectResponse
    {
        $opname = $action->handle($request->document(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Opname {$opname->number} disimpan sebagai draf."]);

        return to_route('admin.stock-opnames.show', ['opname' => $opname]);
    }

    public function show(Request $request, StockOpname $opname): Response
    {
        $user = self::user($request);
        $gate = Gate::forUser($user);
        abort_unless($gate->allows('view', $opname), 404);
        $opname->load(['stockLocation:id,code,name', 'creator:id,name', 'poster:id,name', 'lines']);

        $counted = $opname->lines->filter(fn (StockOpnameLine $l) => $l->isCounted())->count();
        $summary = ['lines' => $opname->lines->count(), 'counted' => $counted, 'found' => $opname->lines->filter->isFound()->count()];
        if (! $opname->hidesSystemQty() && $opname->status !== StockOpnameStatus::Draft) {
            $variances = OpnameVariance::for($opname);
            $summary['variance_lines'] = $opname->lines->filter(fn (StockOpnameLine $l) => OpnameVariance::hasVariance($variances[$l->id]))->count();
            $summary['variance_value'] = $user->can('stock.view_cost')
                ? (string) ($opname->status === StockOpnameStatus::Posted ? $opname->variance_value : OpnameVariance::totalValue($opname))
                : null;
        }

        return Inertia::render('stock-opnames/show', [
            'opname' => [
                'id' => $opname->id,
                'number' => $opname->number,
                'status' => $opname->status->value,
                'status_label' => $opname->status->label(),
                'location' => ['id' => $opname->stockLocation->id, 'code' => $opname->stockLocation->code, 'name' => $opname->stockLocation->name],
                'scope' => $opname->scope->value,
                'scope_label' => $opname->scope->label(),
                'scope_count' => count($opname->scope_ids ?? []),
                'blind_count' => $opname->blind_count,
                'frozen_at' => $opname->frozen_at?->toIso8601String(),
                'approval_request_id' => $opname->approval_request_id,
                'posted_by' => $opname->poster?->name,
                'posted_at' => $opname->posted_at?->toIso8601String(),
                'created_by' => $opname->creator?->name,
                'created_at' => $opname->created_at?->toIso8601String(),
                'note' => $opname->note,
            ],
            'summary' => $summary,
            'history' => self::history($opname),
            'can' => [
                'start' => $gate->allows('start', $opname),
                'count' => $gate->allows('count', $opname),
                'finish' => $gate->allows('finish', $opname),
                'post' => $gate->allows('post', $opname),
                'cancel' => $gate->allows('cancel', $opname),
            ],
        ]);
    }

    public function start(Request $request, StockOpname $opname, StartStockOpname $action): RedirectResponse
    {
        $this->authorizeAction($request, 'start', $opname);
        $saved = $action->handle($opname, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Opname {$saved->number} dimulai. Stok dibekukan untuk dihitung."]);

        return to_route('admin.stock-opnames.lines.index', ['opname' => $saved]);
    }

    public function submit(Request $request, StockOpname $opname, FinishCounting $action): RedirectResponse
    {
        $this->authorizeAction($request, 'finish', $opname);
        $saved = $action->handle($opname, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Hitung {$saved->number} selesai. Tinjau selisihnya."]);

        return to_route('admin.stock-opnames.show', ['opname' => $saved]);
    }

    public function post(Request $request, StockOpname $opname, SubmitStockOpname $action): RedirectResponse
    {
        $this->authorizeAction($request, 'post', $opname);
        $saved = $action->handle($opname, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === StockOpnameStatus::Posted
            ? "Opname {$saved->number} diposting."
            : "Opname {$saved->number} diajukan untuk persetujuan selisih."]);

        return back();
    }

    public function cancel(Request $request, StockOpname $opname, CancelStockOpname $action): RedirectResponse
    {
        $this->authorizeAction($request, 'cancel', $opname);
        $action->handle($opname, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Opname {$opname->number} dibatalkan."]);

        return back();
    }

    /** Lembar hitung (15 §7.1): kolom sistem TIDAK dikirim saat hitung buta & counting (SEC-103). */
    #[UsesListing(StockOpnameLineListing::class)]
    public function lines(Request $request, StockOpname $opname): Response
    {
        $user = self::user($request);
        $gate = Gate::forUser($user);
        abort_unless($gate->allows('view', $opname), 404);
        $opname->load('stockLocation:id,code,name');

        $hidden = $opname->hidesSystemQty();
        $variances = $hidden ? [] : OpnameVariance::for($opname);
        $canCost = $user->can('stock.view_cost');
        $query = StockOpnameLine::query()
            ->select('stock_opname_lines.*')
            ->join('stock_opnames', 'stock_opnames.id', '=', 'stock_opname_lines.stock_opname_id')
            ->join('products', 'products.id', '=', 'stock_opname_lines.product_id')
            ->leftJoin('stock_bins', 'stock_bins.id', '=', 'stock_opname_lines.stock_bin_id')
            ->where('stock_opname_lines.stock_opname_id', $opname->id)
            ->with(['product:id,sku,name,base_unit_id', 'product.baseUnit:id,name', 'bin:id,code', 'counter:id,name', 'recounter:id,name', 'varianceReason:id,name']);
        $result = ListingQuery::apply($query, new StockOpnameLineListing(withVariance: ! $hidden), $request)
            ->through(fn (StockOpnameLine $l) => StockOpnameLineResource::make($l, $opname, $variances[$l->id] ?? null, $canCost));

        return Inertia::render('stock-opnames/count-sheet', [
            ...$result->toInertia(),
            'opname' => [
                'id' => $opname->id,
                'number' => $opname->number,
                'status' => $opname->status->value,
                'status_label' => $opname->status->label(),
                'location' => ['id' => $opname->stockLocation->id, 'name' => $opname->stockLocation->name],
                'blind_count' => $opname->blind_count,
                'hides_system' => $hidden,
            ],
            'reasons' => $opname->status === StockOpnameStatus::Review
                ? ReferenceItem::query()->forGroup(ReferenceGroup::OpnameVarianceReason)->where('is_active', true)->orderBy('sort_order')
                    ->get(['id', 'name'])->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->values()
                : [],
            'can' => ['count' => $gate->allows('count', $opname), 'finish' => $gate->allows('finish', $opname)],
            'me' => $user->id,
        ]);
    }

    public function count(OpnameLineRequest $request, StockOpname $opname, StockOpnameLine $line, RecordCount $count, RecordRecount $recount, SetOpnameVarianceReason $reason): RedirectResponse
    {
        $this->authorizeAction($request, 'count', $opname);
        abort_unless($line->stock_opname_id === $opname->id, 404);
        $userId = self::user($request)->id;
        match ((string) $request->validated('mode')) {
            'count' => $count->handle($opname, $line, $request->qty(), $request->note(), $request->file('photo'), $userId),
            'recount' => $recount->handle($opname, $line, $request->qty(), $request->note(), $userId),
            default => $reason->handle($opname, $line, (int) $request->validated('variance_reason_id'), $request->note()),
        };

        return back();
    }

    public function storeLine(FoundBatchRequest $request, StockOpname $opname, AddFoundBatch $action): RedirectResponse
    {
        $this->authorizeAction($request, 'count', $opname);
        $action->handle($opname, $request->foundBatch(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Batch temuan ditambahkan ke lembar hitung.']);

        return back();
    }

    private function authorizeAction(Request $request, string $ability, StockOpname $opname): void
    {
        $gate = Gate::forUser(self::user($request));
        abort_unless($gate->allows('view', $opname), 404);
        $gate->authorize($ability, $opname);
    }

    /** @return array<int, array<string, mixed>> */
    private static function history(StockOpname $opname): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', $opname->getMorphClass())->where('auditable_id', $opname->id)
            ->whereIn('event', array_keys(self::HISTORY_EVENTS))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get()
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
