<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stok minimum/maksimum produk per lokasi stok (15 §4.8): dasar alert stok minimum & saran PO.
 * Layar pengaturnya dibuat task pemakai.
 *
 * @property int $id
 * @property int $product_id
 * @property int $stock_location_id
 * @property string $min_qty_base
 * @property string|null $max_qty_base
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read StockLocation $stockLocation
 */
class ProductStockLevel extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'stock_location_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class)->withTrashed();
    }
}
