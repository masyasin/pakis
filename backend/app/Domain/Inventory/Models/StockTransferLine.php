<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Support\Money\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris mutasi (15 §4.10). Satu baris = satu batch sumber: baris FEFO yang terbagi ke beberapa batch
 * saat kirim dipecah menjadi beberapa baris (PA-66), sehingga `source/transit/dest_batch_id` selalu
 * tunggal dan telusur batch utuh.
 *
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $product_id
 * @property int|null $source_batch_id
 * @property int|null $transit_batch_id
 * @property int|null $dest_batch_id
 * @property string $qty_requested_base
 * @property string|null $qty_shipped_base
 * @property string|null $qty_received_base
 * @property string|null $variance_reason
 * @property int|null $dest_bin_id
 * @property bool $fefo_override
 * @property-read StockTransfer $transfer
 * @property-read Product $product
 * @property-read StockBatch|null $sourceBatch
 * @property-read StockBatch|null $transitBatch
 * @property-read StockBatch|null $destBatch
 * @property-read StockBin|null $destBin
 */
class StockTransferLine extends Model
{
    protected $table = 'stock_transfer_lines';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_transfer_id' => 'integer',
            'product_id' => 'integer',
            'source_batch_id' => 'integer',
            'transit_batch_id' => 'integer',
            'dest_batch_id' => 'integer',
            'dest_bin_id' => 'integer',
            'fefo_override' => 'boolean',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function sourceBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'source_batch_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function transitBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'transit_batch_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function destBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'dest_batch_id');
    }

    /** @return BelongsTo<StockBin, $this> */
    public function destBin(): BelongsTo
    {
        return $this->belongsTo(StockBin::class, 'dest_bin_id');
    }

    public function qtyRequested(): Qty
    {
        return Qty::of($this->qty_requested_base);
    }

    public function qtyShipped(): Qty
    {
        return Qty::of($this->qty_shipped_base ?? '0');
    }

    public function qtyReceived(): Qty
    {
        return Qty::of($this->qty_received_base ?? '0');
    }

    /** Selisih kurang = dikirim − diterima (≥ 0 karena terima lebih ditolak `TRANSFER_OVER_RECEIPT`). */
    public function variance(): Qty
    {
        return $this->qtyShipped()->minus($this->qtyReceived());
    }
}
