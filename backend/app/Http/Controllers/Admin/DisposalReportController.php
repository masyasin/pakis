<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Actions\CancelDisposalReport;
use App\Domain\Inventory\Actions\ExecuteDisposalReport;
use App\Domain\Inventory\Actions\SaveDisposalReport;
use App\Domain\Inventory\Actions\SubmitDisposalReport;
use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Listings\DisposalReportListing;
use App\Domain\Inventory\Models\DisposalReport;
use App\Domain\Inventory\Models\DisposalReportLine;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\DisposalReportRequest;
use App\Http\Requests\Inventory\ExecuteDisposalRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Berita acara pemusnahan / serah (15 §5 `admin.disposal-reports.*`, FR-A29, BR-163). Semua aksi
 * `stock_disposals.manage` + lokasi dalam scope Stok (di luar → 404). `cancel` & `print` melengkapi
 * 15 §5 (PA-69): batal melepas reservasi; cetak = halaman HTML siap cetak (template generik, OQ-115).
 */
class DisposalReportController extends Controller
{
    private const HISTORY_EVENTS = [
        'disposal_report.created' => 'Berita acara dibuat',
        'disposal_report.submitted' => 'Diajukan',
        'disposal_report.approved' => 'Disetujui (batch ditahan)',
        'disposal_report.rejected' => 'Ditolak',
        'disposal_report.withdrawn' => 'Pengajuan ditarik',
        'disposal_report.executed' => 'Dilaksanakan',
        'disposal_report.cancelled' => 'Dibatalkan',
    ];

    #[UsesListing(DisposalReportListing::class)]
    public function index(Request $request, DataScope $scope): Response
    {
        $query = $scope->forStock(DisposalReport::query()->with(['stockLocation:id,name'])->withCount('lines'), self::user($request), 'disposal_reports.stock_location_id');
        $result = ListingQuery::apply($query, new DisposalReportListing, $request)
            ->through(fn (DisposalReport $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'location' => $r->stockLocation->name,
                'method' => $r->method->value,
                'method_label' => $r->method->label(),
                'lines_count' => (int) $r->getAttribute('lines_count'),
                'total_cost' => $r->total_cost,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'planned_date' => $r->planned_date?->toDateString(),
                'executed_at' => $r->executed_at?->toIso8601String(),
            ]);

        return Inertia::render('disposal-reports/index', $result->toInertia());
    }

    public function create(): Response
    {
        return Inertia::render('disposal-reports/form', [
            'methods' => DisposalMethod::options(),
            'reasons' => self::reasons(ReferenceGroup::DisposalReason),
        ]);
    }

    public function store(DisposalReportRequest $request, SaveDisposalReport $action): RedirectResponse
    {
        $report = $action->handle($request->document(), $request->lines(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Berita acara {$report->number} disimpan sebagai draf."]);

        return to_route('admin.disposal-reports.show', ['report' => $report]);
    }

    public function show(Request $request, DisposalReport $report): Response
    {
        $gate = Gate::forUser(self::user($request));
        abort_unless($gate->allows('view', $report), 404);

        return Inertia::render('disposal-reports/show', [
            'report' => self::detail($report),
            'history' => self::history($report),
            'purchaseReturn' => self::purchaseReturn($report),
            'returnReasons' => $report->method === DisposalMethod::ReturnToPrincipal ? self::reasons(ReferenceGroup::PurchaseReturnReason) : [],
            'can' => [
                'submit' => $gate->allows('submit', $report),
                'execute' => $gate->allows('execute', $report),
                'cancel' => $gate->allows('cancel', $report),
            ],
        ]);
    }

    public function submit(Request $request, DisposalReport $report, SubmitDisposalReport $action): RedirectResponse
    {
        $this->authorizeAction($request, 'submit', $report);
        $saved = $action->handle($report, self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === DisposalReportStatus::Approved
            ? "Berita acara {$saved->number} disetujui."
            : "Berita acara {$saved->number} diajukan untuk persetujuan."]);

        return back();
    }

    public function execute(ExecuteDisposalRequest $request, DisposalReport $report, ExecuteDisposalReport $action): RedirectResponse
    {
        $this->authorizeAction($request, 'execute', $report);
        $action->handle($report, $request->execution(), $request->documentFile(), $request->photoFiles(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $report->method === DisposalMethod::ReturnToPrincipal
            ? "Berita acara {$report->number} dilaksanakan; retur ke prinsipal dibuat sebagai draf."
            : "Berita acara {$report->number} dilaksanakan; stok dikeluarkan."]);

        return back();
    }

    public function cancel(Request $request, DisposalReport $report, CancelDisposalReport $action): RedirectResponse
    {
        $this->authorizeAction($request, 'cancel', $report);
        $action->handle($report, self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Berita acara {$report->number} dibatalkan."]);

        return back();
    }

    /** Cetak berita acara (HTML siap cetak; template generik sampai prosedur dinas OQ-115). */
    public function print(Request $request, DisposalReport $report): Response
    {
        abort_unless(Gate::forUser(self::user($request))->allows('view', $report), 404);

        return Inertia::render('disposal-reports/print', [
            'report' => self::detail($report),
            'company' => setting('general.company_name'),
        ]);
    }

    /** @return array<string, mixed> */
    private static function detail(DisposalReport $report): array
    {
        $report->loadMissing(['stockLocation:id,code,name', 'reason:id,name', 'creator:id,name',
            'lines.product:id,sku,name,base_unit_id', 'lines.product.baseUnit:id,name', 'lines.batch:id,batch_no,expiry_date,status']);

        return [
            'id' => $report->id,
            'number' => $report->number,
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'method' => $report->method->value,
            'method_label' => $report->method->label(),
            'location' => ['id' => $report->stockLocation->id, 'code' => $report->stockLocation->code, 'name' => $report->stockLocation->name],
            'reason' => $report->reason->name,
            'planned_date' => $report->planned_date?->toDateString(),
            'executed_at' => $report->executed_at?->toIso8601String(),
            'executed_location' => $report->executed_location,
            'witnesses' => $report->witnesses ?? [],
            'has_document' => $report->document_path !== null,
            'photo_count' => count($report->photo_paths ?? []),
            'total_cost' => $report->total_cost,
            'approval_request_id' => $report->approval_request_id,
            'note' => $report->note,
            'created_by' => $report->creator?->name,
            'created_at' => $report->created_at?->toIso8601String(),
            'lines' => $report->lines->map(fn (DisposalReportLine $l) => [
                'id' => $l->id,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'unit' => $l->product->baseUnit->name,
                'batch_no' => $l->batch->batch_no,
                'expiry_date' => $l->batch->expiry_date?->toDateString(),
                'batch_status' => $l->batch->status->value,
                'qty_base' => $l->qty_base,
                'unit_cost' => $l->unit_cost,
            ])->values(),
        ];
    }

    /**
     * Retur prinsipal yang dibuat saat BA `return_to_principal` dilaksanakan (dari audit eksekusi).
     *
     * @return array{id: int, number: string}|null
     */
    private static function purchaseReturn(DisposalReport $report): ?array
    {
        $values = AuditLog::query()->where('auditable_type', $report->getMorphClass())->where('auditable_id', $report->id)
            ->where('event', 'disposal_report.executed')->latest('id')->value('new_values');
        $values = is_string($values) ? json_decode($values, true) : $values;

        return is_array($values) && isset($values['purchase_return_id'])
            ? ['id' => (int) $values['purchase_return_id'], 'number' => (string) ($values['purchase_return_number'] ?? '')]
            : null;
    }

    /** @return list<array{value: int, label: string}> */
    private static function reasons(ReferenceGroup $group): array
    {
        return array_values(ReferenceItem::query()->forGroup($group)->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])
            ->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->all());
    }

    private function authorizeAction(Request $request, string $ability, DisposalReport $report): void
    {
        $gate = Gate::forUser(self::user($request));
        abort_unless($gate->allows('view', $report), 404);
        $gate->authorize($ability, $report);
    }

    /** @return array<int, array<string, mixed>> */
    private static function history(DisposalReport $report): array
    {
        return AuditLog::query()->with('user:id,name')
            ->where('auditable_type', $report->getMorphClass())->where('auditable_id', $report->id)
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
