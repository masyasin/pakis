<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Ledger\BatchKey;
use App\Domain\Inventory\Listings\StockMovementListing;
use App\Domain\Inventory\Listings\StockPositionListing;
use App\Domain\Inventory\Listings\StockTraceListing;
use App\Domain\Inventory\Models\ProductCost;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Queries\StockPositions;
use App\Domain\Inventory\Support\SourceDocuments;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Laporan stok (15 §5 `admin.stock.index/export`, `admin.stock-movements.index/export`,
 * `admin.stock.trace`, FR-A30): posisi stok, kartu stok, telusur batch. Semua dalam scope Stok
 * (DataScope). Kolom HPP/nilai/biaya HANYA dikirim bila `stock.view_cost` (SEC-107): tanpa izin,
 * kuncinya tidak ada di props sama sekali, bukan sekadar disembunyikan di UI.
 */
class StockReportController extends Controller
{
    #[UsesListing(StockPositionListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $mode = $request->input('filter.view') === StockPositions::PER_BATCH ? StockPositions::PER_BATCH : StockPositions::PER_PRODUCT;
        $canCost = $user->can('stock.view_cost');

        if ($mode === StockPositions::PER_BATCH) {
            $query = StockPositions::perBatch($user)->with(['product:id,sku,name,base_unit_id', 'product.baseUnit:id,name', 'stockLocation:id,code,name', 'bin:id,code,zone']);
            $result = ListingQuery::apply($query, new StockPositionListing, $request);
            $costs = $canCost ? self::costs(collect($result->paginator()->items())->pluck('product_id')) : [];
            $result = $result->through(fn (StockBatch $b) => [
                'key' => "b{$b->id}",
                'product_id' => $b->product_id,
                'sku' => $b->product->sku,
                'name' => $b->product->name,
                'unit' => $b->product->baseUnit->name,
                'location' => $b->stockLocation->name,
                'bin' => $b->bin?->code,
                'zone' => $b->bin?->zone->value,
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'qty_on_hand' => $b->qty_on_hand,
                'qty_available' => (string) $b->available(),
                ...($canCost ? self::value($b->qty_on_hand, $costs[$b->product_id] ?? '0') : []),
            ]);
        } else {
            $query = StockPositions::perProduct($user)->with(['baseUnit:id,name']);
            $result = ListingQuery::apply($query, new StockPositionListing, $request);
            $costs = $canCost ? self::costs(collect($result->paginator()->items())->pluck('id')) : [];
            $result = $result->through(fn (Product $p) => [
                'key' => "p{$p->id}-{$p->getAttribute('stock_location_id')}",
                'product_id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'unit' => $p->baseUnit->name,
                'location' => (string) $p->getAttribute('location_name'),
                'qty_on_hand' => (string) $p->getAttribute('qty_on_hand'),
                'qty_available' => (string) $p->getAttribute('qty_available'),
                'qty_quarantine' => (string) $p->getAttribute('qty_quarantine'),
                'qty_expired' => (string) $p->getAttribute('qty_expired'),
                'next_expiry' => $p->getAttribute('next_expiry'),
                ...($canCost ? self::value((string) $p->getAttribute('qty_on_hand'), $costs[$p->id] ?? '0') : []),
            ]);
        }

        return Inertia::render('stock/positions', [
            ...$result->toInertia(),
            'mode' => $mode,
            'canExport' => $user->can('stock.export'),
            'canViewCost' => $canCost,
        ]);
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'stock_positions', self::user($request));
    }

    #[UsesListing(StockMovementListing::class)]
    public function movements(Request $request): Response
    {
        $user = self::user($request);
        $canCost = $user->can('stock.view_cost');
        $query = StockMovementListing::base($user)->with(['batch:id,batch_no,expiry_date,product_id,stock_location_id', 'batch.product:id,sku,name', 'batch.stockLocation:id,name', 'user:id,name']);
        $result = ListingQuery::apply($query, new StockMovementListing, $request);
        $docs = SourceDocuments::resolve(collect($result->paginator()->items()));
        $result = $result->through(fn (StockMovement $m) => [
            'id' => $m->id,
            'moved_at' => $m->moved_at->toIso8601String(),
            'business_date' => $m->business_date->toDateString(),
            'sku' => $m->batch->product->sku,
            'name' => $m->batch->product->name,
            'location' => $m->batch->stockLocation->name,
            'batch_no' => $m->batch->batch_no,
            'type' => $m->movement_type->value,
            'type_label' => $m->movement_type->label(),
            'qty' => $m->qty,
            'qty_after' => $m->qty_after,
            'document' => $docs["{$m->source_type}:{$m->source_id}"] ?? null,
            'user' => $m->user?->name,
            ...($canCost ? ['unit_cost' => $m->unit_cost] : []),
        ]);

        return Inertia::render('stock/movements', [
            ...$result->toInertia(),
            'canExport' => $user->can('stock.export'),
            'canViewCost' => $canCost,
        ]);
    }

    public function movementsExport(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'stock_movements', self::user($request));
    }

    #[UsesListing(StockTraceListing::class)]
    public function trace(Request $request): Response
    {
        $user = self::user($request);
        $product = $request->input('filter.product_id');
        $batchNo = trim((string) $request->query('q', ''));
        // Telusur wajib produk + nomor batch (15 §7.3); tanpa keduanya daftar kosong, bukan semua batch.
        $query = app(DataScope::class)->forStock(StockBatch::query()->select('stock_batches.*')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_batches.stock_location_id'), $user, 'stock_batches.stock_location_id')
            ->with(['product:id,sku,name,base_unit_id', 'product.baseUnit:id,name', 'stockLocation:id,code,name', 'bin:id,code'])
            ->when($product === null || $batchNo === '', fn ($q) => $q->whereRaw('1 = 0'));
        $result = ListingQuery::apply($query, new StockTraceListing, $request)
            ->through(fn (StockBatch $b) => [
                'id' => $b->id,
                'product_id' => $b->product_id,
                'sku' => $b->product->sku,
                'name' => $b->product->name,
                'unit' => $b->product->baseUnit->name,
                'location' => $b->stockLocation->name,
                'location_code' => $b->stockLocation->code,
                'bin' => $b->bin?->code,
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'received_date' => $b->received_date?->toDateString(),
                'status' => $b->status->value,
                'status_label' => $b->status->label(),
                'qty_on_hand' => $b->qty_on_hand,
            ]);

        $searched = $product !== null && $batchNo !== '';

        return Inertia::render('stock/trace', [
            ...$result->toInertia(),
            'searched' => $searched,
            'examples' => $searched ? [] : self::traceExamples($user),
        ]);
    }

    /**
     * Contoh telusur (halaman kosong): batch bernomor yang terakhir masuk dalam scope Stok, satu per
     * produk + nomor batch, agar pengguna langsung punya titik mulai tanpa menghafal nomor batch.
     *
     * @return list<array{product_id: int, sku: string, name: string, batch_no: string}>
     */
    private static function traceExamples(User $user): array
    {
        $query = StockBatch::query()->where('stock_batches.batch_no', '<>', BatchKey::NO_BATCH)
            ->groupBy('stock_batches.product_id', 'stock_batches.batch_no')
            ->selectRaw('stock_batches.product_id, stock_batches.batch_no, MAX(stock_batches.id) AS last_id')
            ->orderByDesc('last_id')->limit(6);
        $rows = app(DataScope::class)->forStock($query, $user, 'stock_batches.stock_location_id')->get();
        $products = Product::query()->whereIn('id', $rows->pluck('product_id'))->get(['id', 'sku', 'name'])->keyBy('id');

        return array_values($rows->filter(fn ($r) => isset($products[$r->product_id]))->map(fn ($r) => [
            'product_id' => (int) $r->product_id,
            'sku' => $products[$r->product_id]->sku,
            'name' => $products[$r->product_id]->name,
            'batch_no' => (string) $r->batch_no,
        ])->all());
    }

    /**
     * @param  Collection<int, int>  $productIds
     * @return array<int, string>
     */
    private static function costs($productIds): array
    {
        return ProductCost::query()->whereIn('product_id', $productIds->unique()->values())->pluck('avg_cost', 'product_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    /** @return array{avg_cost: string, value: string} */
    private static function value(string $qty, string $avg): array
    {
        return ['avg_cost' => $avg, 'value' => (string) Money::of(BigDecimal::of($qty)->multipliedBy($avg))];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return $user;
    }
}
