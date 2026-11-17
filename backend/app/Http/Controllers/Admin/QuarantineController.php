<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Actions\MoveBin;
use App\Domain\Inventory\Actions\QuarantineStock;
use App\Domain\Inventory\Actions\ReleaseQuarantine;
use App\Domain\Inventory\Enums\QuarantineReleaseStatus;
use App\Domain\Inventory\Listings\StockBatchListing;
use App\Domain\Inventory\Models\QuarantineRelease;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\QuarantineRequest;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Karantina (keputusan Mas PA-69) + aksi 15 §5 `admin.stock.quarantine` / `release` /
 * `bin-move` (BR-164). Semua di bawah `stock_quarantine.manage`; batch di luar scope Stok → 404
 * (IDOR). Lepas karantina diputuskan pemegang `stock_quarantine.release` lewat persetujuan.
 */
class QuarantineController extends Controller
{
    #[UsesListing(StockBatchListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $query = StockBatchListing::query($user)
            ->with(['product:id,sku,name,base_unit_id', 'product.baseUnit:id,name', 'stockLocation:id,name', 'bin:id,code,zone']);
        $pending = QuarantineRelease::query()->where('status', QuarantineReleaseStatus::Pending->value)->pluck('stock_batch_id')->flip();

        $result = ListingQuery::apply($query, new StockBatchListing, $request)
            ->through(fn (StockBatch $b) => [
                'id' => $b->id,
                'sku' => $b->product->sku,
                'name' => $b->product->name,
                'unit' => $b->product->baseUnit->name,
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'location' => ['id' => $b->stock_location_id, 'name' => $b->stockLocation->name],
                'bin' => $b->bin === null ? null : ['id' => $b->bin->id, 'code' => $b->bin->code, 'zone' => $b->bin->zone->value],
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'qty_on_hand' => $b->qty_on_hand,
                'qty_available' => (string) $b->available(),
                'pending_release' => $pending->has($b->id),
            ]);

        return Inertia::render('quarantine/index', [
            ...$result->toInertia(),
            'reasons' => ReferenceItem::query()->forGroup(ReferenceGroup::QuarantineReason)->where('is_active', true)->orderBy('sort_order')
                ->get(['id', 'name'])->map(fn (ReferenceItem $r) => ['value' => $r->id, 'label' => $r->name])->values(),
        ]);
    }

    public function quarantine(QuarantineRequest $request, QuarantineStock $action): RedirectResponse
    {
        $batch = $this->batch($request, (int) $request->validated('stock_batch_id'));
        $binId = $request->validated('stock_bin_id');
        $action->handle($batch, $request->qty(), (int) $request->validated('reason_id'), $binId === null ? null : (int) $binId, $request->note(), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Batch {$batch->batch_no} dipindah ke rak karantina."]);

        return back();
    }

    public function move(QuarantineRequest $request, MoveBin $action): RedirectResponse
    {
        $batch = $this->batch($request, (int) $request->validated('stock_batch_id'));
        $action->handle($batch, (int) $request->validated('stock_bin_id'), $request->qty(), self::user($request)->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Batch {$batch->batch_no} dipindah rak."]);

        return back();
    }

    public function release(QuarantineRequest $request, StockBatch $batch, ReleaseQuarantine $action): RedirectResponse
    {
        $batch = $this->batch($request, $batch->id);
        $saved = $action->handle($batch, $request->qty(), (int) $request->validated('target_bin_id'), trim((string) $request->validated('reason')), self::user($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => $saved->status === QuarantineReleaseStatus::Approved
            ? "Batch {$batch->batch_no} dilepas dari karantina."
            : "Permintaan lepas karantina batch {$batch->batch_no} diajukan."]);

        return back();
    }

    /** Batch dalam scope Stok pengguna; di luar scope atau tidak ada → 404 (IDOR). */
    private function batch(Request $request, int $id): StockBatch
    {
        $batch = $this->scoped(StockBatch::query()->whereKey($id), self::user($request))->first();
        abort_if($batch === null, 404);

        return $batch;
    }

    /**
     * @param  Builder<StockBatch>  $query
     * @return Builder<StockBatch>
     */
    private function scoped(Builder $query, User $user): Builder
    {
        return app(DataScope::class)->forStock($query, $user, 'stock_batches.stock_location_id');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
