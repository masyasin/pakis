<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produk yang dipasok supplier (15 §4.5): SKU supplier, lead time, penanda supplier utama, dan harga
 * beli terakhir. PK komposit (supplier, produk) — Eloquent dipakai untuk BACA saja; penulisan lewat
 * query builder di SaveSupplier. `last_price`/`last_price_unit_id` hanya diisi penerimaan barang
 * (T-080, string desimal, ADR-101 tanpa float).
 *
 * @property int $supplier_id
 * @property int $product_id
 * @property string|null $supplier_sku
 * @property string|null $last_price
 * @property int|null $last_price_unit_id
 * @property int|null $lead_time_days
 * @property bool $is_preferred
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductUnit|null $lastPriceUnit
 */
class SupplierProduct extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'product_id';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'product_id' => 'integer',
            'last_price' => 'decimal:2',
            'last_price_unit_id' => 'integer',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function lastPriceUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'last_price_unit_id');
    }
}
