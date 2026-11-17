<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mulai opname (draf → `counting`, BR-162(1)(8)): snapshot `qty_system_base` per batch dalam
 * cakupan pada `frozen_at`. Batch yang masuk = semua batch ber-stok di lokasi itu, termasuk
 * karantina & kedaluwarsa (stok fisik ada di rak; keputusan Mas, PA-67); `disposed` tidak.
 *
 * Satu batch hanya di satu opname aktif (`counting`/`review`/`pending_approval`) → selain itu
 * `OPNAME_BATCH_LOCKED` 409. Baris `stock_locations` dikunci agar dua opname di lokasi yang sama
 * tidak lolos bersamaan; batch dibaca `LOCK IN SHARE MODE` supaya mutasi yang sedang berjalan selesai
 * dulu, dan `frozen_at` diambil setelah kunci, sehingga mutasi sesudahnya pasti bertanggal > `frozen_at`.
 */
final class StartStockOpname
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(StockOpname $opname, int $userId): StockOpname
    {
        // READ COMMITTED + retry bentrok kunci seperti transaksi buku stok (PA-62).
        return StockLedger::transaction(function () use ($opname, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== StockOpnameStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya opname draf yang bisa dimulai.');
            }
            StockLocation::query()->whereKey($locked->stock_location_id)->lockForUpdate()->firstOrFail();

            $batches = $this->scoped(StockBatch::query(), $locked)
                ->where('stock_batches.stock_location_id', $locked->stock_location_id)
                ->where('stock_batches.qty_on_hand', '>', 0)
                ->where('stock_batches.status', '<>', StockBatchStatus::Disposed->value)
                ->orderBy('stock_batches.id')
                ->sharedLock()
                ->get(['stock_batches.*']);

            $busy = StockOpnameLine::query()
                ->join('stock_opnames', 'stock_opnames.id', '=', 'stock_opname_lines.stock_opname_id')
                ->whereIn('stock_opnames.status', StockOpnameStatus::locking())
                ->where('stock_opnames.id', '<>', $locked->id)
                ->whereIn('stock_opname_lines.stock_batch_id', $batches->pluck('id'))
                ->distinct()->pluck('stock_opnames.number')->all();
            if ($busy !== []) {
                throw new ApiError(ErrorCode::OPNAME_BATCH_LOCKED, ['opnames' => $busy]);
            }

            // Milidetik wajib: dibandingkan dengan `stock_movements.moved_at` DATETIME(3); cast datetime
            // Eloquent membuang pecahan detik, jadi ditulis lewat query builder.
            $frozen = now()->format('Y-m-d H:i:s.v');
            $rows = $batches->map(fn (StockBatch $b) => [
                'stock_opname_id' => $locked->id,
                'stock_batch_id' => $b->id,
                'product_id' => $b->product_id,
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'stock_bin_id' => $b->stock_bin_id,
                'qty_system_base' => $b->qty_on_hand,
            ])->all();
            foreach (array_chunk($rows, 500) as $chunk) {
                StockOpnameLine::query()->insert($chunk);
            }

            $before = $locked->audited();
            DB::table('stock_opnames')->where('id', $locked->id)->update([
                'status' => StockOpnameStatus::Counting->value, 'frozen_at' => $frozen, 'updated_by' => $userId, 'updated_at' => now(),
            ]);
            $locked->refresh();
            $this->audit->record('stock_opname.started', $locked, $before, [...$locked->audited(), 'lines' => count($rows)]);

            return $locked;
        });
    }

    /**
     * @param  Builder<StockBatch>  $query
     * @return Builder<StockBatch>
     */
    private function scoped(Builder $query, StockOpname $opname): Builder
    {
        $ids = $opname->scope_ids ?? [];

        return match ($opname->scope) {
            StockOpnameScope::Full => $query,
            StockOpnameScope::Bin => $query->whereIn('stock_batches.stock_bin_id', $ids),
            StockOpnameScope::Product => $query->whereIn('stock_batches.product_id', $ids),
            StockOpnameScope::Category => $query->whereIn('stock_batches.product_id',
                fn ($sub) => $sub->select('id')->from('products')->whereIn('product_category_id', $ids)),
        };
    }
}
