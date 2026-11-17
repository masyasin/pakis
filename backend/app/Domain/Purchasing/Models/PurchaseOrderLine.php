<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris PO (15 §4.9). `qty` dalam satuan baris, `qty_base` = qty × faktor; `line_total` = qty × harga
 * × (1 − diskon%). `qty_received_base` diisi penerimaan barang (T-080). String desimal (ADR-101).
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_no
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $qty
 * @property string $qty_base
 * @property string $unit_price
 * @property string $discount_pct
 * @property string $line_total
 * @property string $qty_received_base
 * @property string|null $note
 * @property-read Product $product
 * @property-read ProductUnit $productUnit
 */
class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'line_no' => 'integer',
            'product_id' => 'integer',
            'product_unit_id' => 'integer',
            'qty' => 'decimal:3',
            'qty_base' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_pct' => 'decimal:4',
            'line_total' => 'decimal:2',
            'qty_received_base' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
