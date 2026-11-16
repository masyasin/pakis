<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Harga satu satuan produk pada satu versi (15 §4.7). `min_qty` dalam satuan baris ini: tier dipilih
 * `min_qty` terbesar ≤ qty (BR-168). Nilai string desimal (ADR-101, tanpa float).
 *
 * @property int $id
 * @property int $price_list_version_id
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $min_qty
 * @property string $price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ProductUnit $productUnit
 * @property-read PriceListVersion $version
 */
class PriceListItem extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'price_list_version_id' => 'integer',
            'product_id' => 'integer',
            'product_unit_id' => 'integer',
            'min_qty' => 'decimal:3',
            'price' => 'decimal:2',
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

    /** @return BelongsTo<PriceListVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class, 'price_list_version_id');
    }
}
