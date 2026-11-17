<?php

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Target promo: produk, kategori, atau prinsipal (15 §4.7).
 *
 * @property int $id
 * @property int $promotion_id
 * @property string $target_type
 * @property int $target_id
 */
class PromotionTarget extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['promotion_id' => 'integer', 'target_id' => 'integer'];
    }
}
