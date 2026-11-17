<?php

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cakupan promo: semua, daftar harga, area, atau kios (15 §4.7). `scope_id` null untuk `all`.
 *
 * @property int $id
 * @property int $promotion_id
 * @property string $scope_type
 * @property int|null $scope_id
 */
class PromotionScope extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['promotion_id' => 'integer', 'scope_id' => 'integer'];
    }
}
