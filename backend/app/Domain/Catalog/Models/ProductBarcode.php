<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Barcode per satuan produk (FR-A09): unik global (satu barcode = satu produk & satuan), bisa
 * lebih dari satu per satuan. Pemindai di penerimaan/opname/POS mencocokkan nilai persis.
 *
 * @property int $id
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $barcode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProductUnit $productUnit
 */
class ProductBarcode extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return ['product_id' => 'integer', 'product_unit_id' => 'integer'];
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
