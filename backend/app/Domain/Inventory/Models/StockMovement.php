<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Ledger\MovementType;
use App\Domain\Inventory\Models\Concerns\LedgerOnlyBuilder;
use App\Domain\Inventory\Models\Concerns\WrittenByLedgerOnly;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris kartu stok (15 §4.8, BR-154). Append-only: trigger DB menolak UPDATE/DELETE dan model
 * ini baca-saja; hanya StockLedger yang menulis.
 *
 * @property int $id
 * @property int $stock_batch_id
 * @property int $product_id
 * @property int $stock_location_id
 * @property MovementType $movement_type
 * @property string $qty
 * @property string $qty_after
 * @property string $unit_cost
 * @property string $source_type
 * @property int $source_id
 * @property int|null $source_line_id
 * @property Carbon $business_date
 * @property Carbon $moved_at
 * @property int|null $user_id
 * @property string|null $note
 * @property-read StockBatch $batch
 */
#[UseEloquentBuilder(LedgerOnlyBuilder::class)]
class StockMovement extends Model
{
    use WrittenByLedgerOnly;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'stock_batch_id' => 'integer',
            'product_id' => 'integer',
            'stock_location_id' => 'integer',
            'movement_type' => MovementType::class,
            'source_id' => 'integer',
            'source_line_id' => 'integer',
            'business_date' => 'date',
            'moved_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    public function qtyValue(): Qty
    {
        return Qty::of($this->qty);
    }

    public function qtyAfterValue(): Qty
    {
        return Qty::of($this->qty_after);
    }

    public function unitCostValue(): UnitCost
    {
        return UnitCost::of($this->unit_cost);
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
