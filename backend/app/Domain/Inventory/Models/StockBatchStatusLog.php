<?php

namespace App\Domain\Inventory\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat status batch (15 §4.8; BR-157 kedaluwarsa otomatis, BR-164 karantina). Ditulis
 * `StockLedger::setStatus()`; `user_id` NULL = job sistem.
 *
 * @property int $id
 * @property int $stock_batch_id
 * @property string $from_status
 * @property string $to_status
 * @property int|null $reason_id
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read StockBatch $batch
 */
class StockBatchStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_batch_id' => 'integer',
            'reason_id' => 'integer',
            'user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
