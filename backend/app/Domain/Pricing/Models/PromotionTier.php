<?php

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tier promo (15 §4.7): ambang qty satuan dasar/nominal → diskon %, diskon nominal per satuan dasar,
 * harga bundel, atau bonus produk. String desimal (ADR-101).
 *
 * @property int $id
 * @property int $promotion_id
 * @property string|null $min_qty_base
 * @property string|null $min_amount
 * @property string|null $discount_pct
 * @property string|null $discount_amount
 * @property string|null $bundle_price
 * @property int|null $free_product_id
 * @property string|null $free_qty_base
 */
class PromotionTier extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'promotion_id' => 'integer',
            'min_qty_base' => 'decimal:3',
            'min_amount' => 'decimal:2',
            'discount_pct' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'bundle_price' => 'decimal:2',
            'free_product_id' => 'integer',
            'free_qty_base' => 'decimal:3',
        ];
    }
}
