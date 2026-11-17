<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Listings\FefoViolationListing;
use App\Domain\Inventory\Listings\OpnameCounterListing;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Laporan Audit Stok (PA-72, izin `stock.audit_reports`, bawaan hanya Owner): satu halaman bertab.
 * - Pelanggaran FEFO (PA-64 b, BR-156): baris mutasi terkirim yang memakai batch selain kandidat FEFO
 *   pertama. Paket B menambah baris penjualan.
 * - Selisih per penghitung (PA-67 a, SEC-103/TA-01): per opname × penghitung pertama, berapa baris
 *   yang hitungan pertamanya menyimpang dari ekspektasi (snapshot + mutasi setelah freeze, rumus
 *   OpnameVariance) dan berapa yang hitung ulangnya berbeda. Pola menyimpang berulang = sinyal fraud.
 * Keduanya tetap dalam scope Stok lokasi (Owner = semua).
 */
class StockAuditReportController extends Controller
{
    public function __construct(private readonly DataScope $scope) {}

    #[UsesListing(FefoViolationListing::class)]
    public function fefo(Request $request): Response
    {
        $user = self::user($request);
        $query = StockTransferLine::query()
            ->select('stock_transfer_lines.*')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.stock_transfer_id')
            ->join('products', 'products.id', '=', 'stock_transfer_lines.product_id')
            ->leftJoin('stock_batches', 'stock_batches.id', '=', 'stock_transfer_lines.source_batch_id')
            ->where('stock_transfer_lines.fefo_override', true)
            // Draf belum memakai batch apa pun; batal tidak pernah keluar gudang.
            ->whereNotIn('stock_transfers.status', [StockTransferStatus::Draft->value, StockTransferStatus::Cancelled->value])
            ->with(['transfer:id,number,from_stock_location_id,to_stock_location_id,shipped_by,shipped_at,created_at', 'transfer.fromLocation:id,name',
                'transfer.toLocation:id,name', 'transfer.shipper:id,name', 'product:id,sku,name,base_unit_id', 'product.baseUnit:id,name', 'sourceBatch:id,batch_no,expiry_date']);
        $query = $this->scope->forStock($query, $user, 'stock_transfers.from_stock_location_id');

        $result = ListingQuery::apply($query, new FefoViolationListing, $request)
            ->through(fn (StockTransferLine $l) => [
                'id' => $l->id,
                'document' => ['type' => 'stock_transfer', 'number' => $l->transfer->number, 'url' => route('admin.stock-transfers.show', $l->stock_transfer_id, false)],
                'created_at' => $l->transfer->created_at?->toIso8601String(),
                'shipped_at' => $l->transfer->shipped_at?->toIso8601String(),
                'from' => $l->transfer->fromLocation->name,
                'to' => $l->transfer->toLocation->name,
                'sku' => $l->product->sku,
                'name' => $l->product->name,
                'unit' => $l->product->baseUnit->name,
                'batch_no' => $l->sourceBatch?->batch_no,
                'expiry_date' => $l->sourceBatch?->expiry_date?->toDateString(),
                'qty' => $l->qty_shipped_base ?? $l->qty_requested_base,
                'user' => $l->transfer->shipper?->name,
            ]);

        return Inertia::render('stock-audit/index', [...$result->toInertia(), 'tab' => 'fefo']);
    }

    #[UsesListing(OpnameCounterListing::class)]
    public function counters(Request $request): Response
    {
        $user = self::user($request);
        $query = $this->scope->forStock(self::counterBase(), $user, 'stock_opnames.stock_location_id');

        $result = ListingQuery::apply($query, new OpnameCounterListing, $request)
            ->through(fn (StockOpname $o) => [
                'key' => "{$o->id}-{$o->getAttribute('counted_by')}",
                'opname' => ['id' => $o->id, 'number' => $o->number, 'url' => route('admin.stock-opnames.show', $o->id, false)],
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'location' => $o->stockLocation->name,
                'frozen_at' => $o->frozen_at?->toIso8601String(),
                'counter' => (string) $o->getAttribute('counter_name'),
                'lines_counted' => (int) $o->getAttribute('lines_counted'),
                'variance_lines' => (int) $o->getAttribute('variance_lines'),
                'variance_qty' => (string) $o->getAttribute('variance_qty'),
                'recount_differs' => (int) $o->getAttribute('recount_differs'),
            ]);

        return Inertia::render('stock-audit/index', [...$result->toInertia(), 'tab' => 'counters']);
    }

    /**
     * Opname × penghitung pertama dengan agregat `agg`. Ekspektasi per baris = snapshot + Σ mutasi batch
     * setelah `frozen_at` di luar posting opname itu sendiri — sama dengan OpnameVariance, dihitung di
     * SQL agar listing bisa difilter/diurutkan tanpa memuat semua baris. Tipe sumber dikirim sebagai
     * binding, bukan interpolasi.
     *
     * @return Builder<StockOpname>
     */
    private static function counterBase()
    {
        $expected = '(l.qty_system_base + COALESCE((SELECT SUM(m.qty) FROM stock_movements m WHERE m.stock_batch_id = l.stock_batch_id'
            .' AND m.moved_at > o.frozen_at AND NOT (m.source_type = ? AND m.source_id = o.id)), 0))';
        $agg = DB::table('stock_opname_lines as l')
            ->join('stock_opnames as o', 'o.id', '=', 'l.stock_opname_id')
            ->whereNotNull('l.counted_by')->whereNotNull('l.qty_counted_base')->whereNotNull('o.frozen_at')
            ->groupBy('l.stock_opname_id', 'l.counted_by')
            ->selectRaw('l.stock_opname_id, l.counted_by, COUNT(*) AS lines_counted,'
                ." SUM(CASE WHEN l.qty_counted_base <> {$expected} THEN 1 ELSE 0 END) AS variance_lines,"
                ." SUM(ABS(l.qty_counted_base - {$expected})) AS variance_qty,"
                .' SUM(CASE WHEN l.qty_recount_base IS NOT NULL AND l.qty_recount_base <> l.qty_counted_base THEN 1 ELSE 0 END) AS recount_differs',
                [StockOpname::SOURCE_TYPE, StockOpname::SOURCE_TYPE]);

        return StockOpname::query()
            ->select('stock_opnames.*', 'agg.counted_by', 'agg.lines_counted', 'agg.variance_lines', 'agg.variance_qty', 'agg.recount_differs', 'cu.name as counter_name')
            ->joinSub($agg, 'agg', 'agg.stock_opname_id', '=', 'stock_opnames.id')
            ->leftJoin('users as cu', 'cu.id', '=', 'agg.counted_by')
            ->with('stockLocation:id,name');
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
