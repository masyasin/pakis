<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris retur (15 §4.9): satu batch, qty satuan dasar, `unit_cost` = biaya beli batch per satuan dasar
 * (BR-159). `reservation_id` diisi saat disetujui (BR-155). String desimal (ADR-101).
 *
 * @property int $id
 * @property int $purchase_return_id
 * @property int $stock_batch_id
 * @property int $product_id
 * @property string $qty_base
 * @property string $unit_cost
 * @property int|null $reservation_id
 * @property-read PurchaseReturn $purchaseReturn
 * @property-read StockBatch $batch
 * @property-read Product $product
 * @property-read StockReservation|null $reservation
 */
class PurchaseReturnLine extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'purchase_return_id' => 'integer',
            'stock_batch_id' => 'integer',
            'product_id' => 'integer',
            'qty_base' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'reservation_id' => 'integer',
        ];
    }

    /** @return BelongsTo<PurchaseReturn, $this> */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<StockReservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(StockReservation::class, 'reservation_id');
    }
}
