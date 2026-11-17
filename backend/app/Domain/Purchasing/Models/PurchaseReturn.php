<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retur ke prinsipal (15 §4.9, FR-A26, BR-155, BR-159). Alur `draft → pending_approval → approved →
 * shipped → closed`; `rejected` dari approval, `cancelled` dari draf/disetujui/ditolak (PA-68).
 * `total_cost` = Σ qty × biaya beli batch (dihitung server).
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int $stock_location_id
 * @property int|null $goods_receipt_id
 * @property int $reason_id
 * @property PurchaseReturnStatus $status
 * @property int|null $approval_request_id
 * @property string $total_cost
 * @property Carbon|null $shipped_at
 * @property Carbon|null $closed_at
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplier $supplier
 * @property-read StockLocation $stockLocation
 * @property-read GoodsReceipt|null $goodsReceipt
 * @property-read ReferenceItem $reason
 * @property-read User|null $creator
 * @property-read Collection<int, PurchaseReturnLine> $lines
 */
class PurchaseReturn extends Model
{
    protected $table = 'purchase_returns';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'stock_location_id' => 'integer',
            'goods_receipt_id' => 'integer',
            'reason_id' => 'integer',
            'status' => PurchaseReturnStatus::class,
            'approval_request_id' => 'integer',
            'total_cost' => 'decimal:2',
            'shipped_at' => 'datetime',
            'closed_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id')->withTrashed();
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'reason_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReturnLine::class, 'purchase_return_id')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'number' => $this->number,
            'supplier_id' => $this->supplier_id,
            'stock_location_id' => $this->stock_location_id,
            'goods_receipt_id' => $this->goods_receipt_id,
            'reason_id' => $this->reason_id,
            'status' => $this->status->value,
            'total_cost' => $this->total_cost,
            'note' => $this->note,
        ];
    }
}
