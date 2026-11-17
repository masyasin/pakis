<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Purchase order (15 §4.9, FR-A24, BR-160). Hanya draf yang bisa diubah; disetujui → tutup lalu
 * buat PO baru. Total selalu dihitung server (PurchaseOrderTotals). `expected_date`/`order_date`
 * tanggal sipil lokal.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int $stock_location_id
 * @property Carbon $order_date
 * @property Carbon|null $expected_date
 * @property int $payment_term_days
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $grand_total
 * @property PurchaseOrderStatus $status
 * @property int|null $approval_request_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $closed_at
 * @property string|null $close_reason
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplier $supplier
 * @property-read StockLocation $stockLocation
 * @property-read User|null $creator
 */
class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'stock_location_id' => 'integer',
            'order_date' => 'date:Y-m-d',
            'expected_date' => 'date:Y-m-d',
            'payment_term_days' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'status' => PurchaseOrderStatus::class,
            'approval_request_id' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id')->orderBy('line_no');
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'number' => $this->number,
            'supplier_id' => $this->supplier_id,
            'stock_location_id' => $this->stock_location_id,
            'order_date' => $this->order_date->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'payment_term_days' => $this->payment_term_days,
            'grand_total' => $this->grand_total,
            'status' => $this->status->value,
            'close_reason' => $this->close_reason,
        ];
    }
}
