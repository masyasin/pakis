<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Support\Money\Qty;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reservasi qty batch untuk dokumen (BR-155). Ikut menggerakkan `stock_batches.qty_reserved`, jadi
 * dibuat, dikonsumsi, dan dilepas hanya lewat StockLedger (`reserve`/`consume`/`release`).
 *
 * @property int $id
 * @property int $stock_batch_id
 * @property string $qty
 * @property string $source_type
 * @property int $source_id
 * @property int|null $source_line_id
 * @property ReservationStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockBatch $batch
 */
class StockReservation extends Model
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_batch_id' => 'integer',
            'source_id' => 'integer',
            'source_line_id' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function qtyValue(): Qty
    {
        return Qty::of($this->qty);
    }

    public function isActive(): bool
    {
        return $this->status === ReservationStatus::Active;
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
