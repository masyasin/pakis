<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\Concerns\LedgerOnlyBuilder;
use App\Domain\Inventory\Models\Concerns\WrittenByLedgerOnly;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Saldo satu batch di satu lokasi stok + rak (15 §4.8, ADR-103). Baca-saja di luar StockLedger.
 * Kolom qty/biaya dibaca sebagai string desimal (tanpa float) lewat `onHand()`/`available()`.
 *
 * @property int $id
 * @property int $stock_location_id
 * @property int|null $stock_bin_id
 * @property int $product_id
 * @property string $batch_no
 * @property Carbon|null $expiry_date
 * @property Carbon|null $received_date
 * @property string|null $receipt_unit_cost
 * @property string $qty_on_hand
 * @property string $qty_reserved
 * @property StockBatchStatus $status
 * @property int|null $quarantine_reason_id
 * @property int $bin_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockLocation $stockLocation
 * @property-read StockBin|null $bin
 * @property-read Product $product
 */
#[UseEloquentBuilder(LedgerOnlyBuilder::class)]
class StockBatch extends Model
{
    use WrittenByLedgerOnly;

    protected function casts(): array
    {
        return [
            'stock_location_id' => 'integer',
            'stock_bin_id' => 'integer',
            'product_id' => 'integer',
            'expiry_date' => 'date',
            'received_date' => 'date',
            'status' => StockBatchStatus::class,
            'quarantine_reason_id' => 'integer',
            'bin_key' => 'integer',
        ];
    }

    public function onHand(): Qty
    {
        return Qty::of($this->qty_on_hand);
    }

    public function reserved(): Qty
    {
        return Qty::of($this->qty_reserved);
    }

    /** Qty tersedia = on hand − reserved (BR-155). */
    public function available(): Qty
    {
        return $this->onHand()->minus($this->reserved());
    }

    public function receiptUnitCost(): ?UnitCost
    {
        return $this->receipt_unit_cost === null ? null : UnitCost::of($this->receipt_unit_cost);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class)->withTrashed();
    }

    /** @return BelongsTo<StockBin, $this> */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(StockBin::class, 'stock_bin_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
