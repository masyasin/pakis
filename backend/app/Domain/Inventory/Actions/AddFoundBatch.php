<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameLine;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch ditemukan saat hitung yang tidak ada di snapshot (BR-162(7)): baris baru `stock_batch_id`
 * NULL, `qty_system_base` 0. Batch-nya dibuat saat posting lewat `adjustment_in` dengan biaya
 * `avg_cost` (event `inventory.adjusted`, 15 §11.4). Produk/rak wajib di dalam cakupan opname.
 */
final class AddFoundBatch
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{product_id: int, batch_no: string, expiry_date: string|null, stock_bin_id: int|null, qty: string, note: string|null}  $data */
    public function handle(StockOpname $opname, array $data, int $userId): StockOpnameLine
    {
        return DB::transaction(function () use ($opname, $data, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== StockOpnameStatus::Counting) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Lembar hitung sudah ditutup.');
            }
            $product = Product::query()->find($data['product_id']);
            if ($product === null || $product->status !== ProductStatus::Active) {
                throw ValidationException::withMessages(['product_id' => 'Produk tidak ditemukan atau tidak aktif.']);
            }
            $ids = $locked->scope_ids ?? [];
            $outOfScope = match ($locked->scope) {
                StockOpnameScope::Product => ! in_array($product->id, $ids, true),
                StockOpnameScope::Category => ! in_array($product->product_category_id, $ids, true),
                default => false,
            };
            if ($outOfScope) {
                throw ValidationException::withMessages(['product_id' => 'Produk ini di luar cakupan opname.']);
            }
            if ($data['stock_bin_id'] !== null) {
                $bin = StockBin::query()->whereKey($data['stock_bin_id'])->where('stock_location_id', $locked->stock_location_id)->first();
                if ($bin === null || ($locked->scope === StockOpnameScope::Bin && ! in_array($bin->id, $ids, true))) {
                    throw ValidationException::withMessages(['stock_bin_id' => 'Rak tidak ada di lokasi atau di luar cakupan opname.']);
                }
            } elseif ($locked->scope === StockOpnameScope::Bin) {
                throw ValidationException::withMessages(['stock_bin_id' => 'Pilih rak tempat batch ditemukan.']);
            }

            $batchNo = mb_strtoupper(trim($data['batch_no']));
            $duplicate = StockOpnameLine::query()->where('stock_opname_id', $locked->id)->where('product_id', $product->id)
                ->where('batch_no', $batchNo)->where('expiry_date', $data['expiry_date'])->where('stock_bin_id', $data['stock_bin_id'])->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['batch_no' => 'Batch ini sudah ada di lembar hitung. Isi jumlahnya di baris itu.']);
            }

            $line = new StockOpnameLine;
            $line->forceFill([
                'stock_opname_id' => $locked->id,
                'stock_batch_id' => null,
                'product_id' => $product->id,
                'batch_no' => $batchNo,
                'expiry_date' => $data['expiry_date'],
                'stock_bin_id' => $data['stock_bin_id'],
                'qty_system_base' => '0',
                'qty_counted_base' => (string) Qty::of($data['qty']),
                'counted_by' => $userId,
                'counted_at' => now(),
                'note' => $data['note'],
            ])->save();
            $this->audit->record('stock_opname.found_batch', $locked, null, [
                'line_id' => $line->id, 'product_id' => $product->id, 'batch_no' => $batchNo, 'qty_counted_base' => $line->qty_counted_base,
            ]);

            return $line;
        });
    }
}
