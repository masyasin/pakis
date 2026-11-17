<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HPP moving average per produk (BR-114/159), dihitung `Ledger\CostCalculator` saat penerimaan.
 *
 * @property int $product_id
 * @property string $avg_cost
 * @property string $qty_basis
 * @property Carbon|null $last_receipt_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 */
class ProductCost extends Model
{
    public const CREATED_AT = null;

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'last_receipt_at' => 'datetime',
        ];
    }

    public function avgCost(): UnitCost
    {
        return UnitCost::of($this->avg_cost);
    }

    public function qtyBasis(): Qty
    {
        return Qty::of($this->qty_basis);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
