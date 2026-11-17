<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Money\Qty;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris opname (15 §4.10): satu batch (snapshot) atau batch temuan (`stock_batch_id` NULL,
 * BR-162(7)). `qty_system_base` = saldo pada `frozen_at`; hitung final = hitung ulang bila ada.
 *
 * @property int $id
 * @property int $stock_opname_id
 * @property int|null $stock_batch_id
 * @property int $product_id
 * @property string $batch_no
 * @property Carbon|null $expiry_date
 * @property int|null $stock_bin_id
 * @property string $qty_system_base
 * @property string|null $qty_counted_base
 * @property string|null $qty_recount_base
 * @property int|null $counted_by
 * @property Carbon|null $counted_at
 * @property int|null $recounted_by
 * @property Carbon|null $recounted_at
 * @property int|null $variance_reason_id
 * @property string|null $note
 * @property string|null $photo_path
 * @property-read StockOpname $opname
 * @property-read Product $product
 * @property-read StockBatch|null $batch
 * @property-read StockBin|null $bin
 * @property-read User|null $counter
 * @property-read User|null $recounter
 * @property-read ReferenceItem|null $varianceReason
 */
class StockOpnameLine extends Model
{
    protected $table = 'stock_opname_lines';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_opname_id' => 'integer',
            'stock_batch_id' => 'integer',
            'product_id' => 'integer',
            'expiry_date' => 'date',
            'stock_bin_id' => 'integer',
            'counted_by' => 'integer',
            'counted_at' => 'datetime',
            'recounted_by' => 'integer',
            'recounted_at' => 'datetime',
            'variance_reason_id' => 'integer',
        ];
    }

    /** @return BelongsTo<StockOpname, $this> */
    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<StockBin, $this> */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(StockBin::class, 'stock_bin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function recounter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recounted_by');
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function varianceReason(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'variance_reason_id');
    }

    public function isFound(): bool
    {
        return $this->stock_batch_id === null;
    }

    public function isCounted(): bool
    {
        return $this->qty_counted_base !== null;
    }

    /** Hasil hitung final: hitung ulang (orang kedua) mengalahkan hitung pertama. */
    public function finalQty(): ?Qty
    {
        $value = $this->qty_recount_base ?? $this->qty_counted_base;

        return $value === null ? null : Qty::of($value);
    }
}
