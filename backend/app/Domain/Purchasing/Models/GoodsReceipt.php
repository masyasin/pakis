<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penerimaan barang (15 §4.9, FR-A25, BR-158). Alur `draft → checked → (pending_approval) → posted`;
 * `rejected` hanya dari approval sisa umur pendek. `received_at` instan UTC; tanggal bisnis mutasi &
 * outbox = tanggal lokalnya (`businessDate()`). Harga beli per baris datang dari PO (SEC-104).
 *
 * @property int $id
 * @property string $number
 * @property int|null $purchase_order_id
 * @property int $supplier_id
 * @property int $stock_location_id
 * @property Carbon $received_at
 * @property string|null $supplier_delivery_no
 * @property string|null $vehicle_plate
 * @property GoodsReceiptStatus $status
 * @property int|null $approval_request_id
 * @property int|null $checked_by
 * @property Carbon|null $checked_at
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplier $supplier
 * @property-read StockLocation $stockLocation
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read User|null $creator
 * @property-read User|null $checker
 * @property-read User|null $poster
 * @property-read Collection<int, GoodsReceiptLine> $lines
 */
class GoodsReceipt extends Model
{
    protected $table = 'goods_receipts';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'supplier_id' => 'integer',
            'stock_location_id' => 'integer',
            'received_at' => 'datetime',
            'status' => GoodsReceiptStatus::class,
            'approval_request_id' => 'integer',
            'checked_by' => 'integer',
            'checked_at' => 'datetime',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
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

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return HasMany<GoodsReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'goods_receipt_id')->orderBy('id');
    }

    /** Tanggal bisnis (tanggal sipil lokal dari `received_at`) untuk mutasi stok, PeriodGuard, dan outbox. */
    public function businessDate(): string
    {
        return DisplayTime::toLocal($this->received_at)->toDateString();
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'number' => $this->number,
            'purchase_order_id' => $this->purchase_order_id,
            'supplier_id' => $this->supplier_id,
            'stock_location_id' => $this->stock_location_id,
            'received_at' => $this->received_at->toIso8601String(),
            'supplier_delivery_no' => $this->supplier_delivery_no,
            'vehicle_plate' => $this->vehicle_plate,
            'status' => $this->status->value,
            'checked_by' => $this->checked_by,
            'posted_by' => $this->posted_by,
        ];
    }
}
