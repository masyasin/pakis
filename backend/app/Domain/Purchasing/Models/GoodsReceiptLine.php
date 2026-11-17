<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Inventory\Models\SerialNumber;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBin;
use App\Domain\Purchasing\Enums\ReceiptLineStatus;
use App\Domain\Reference\Models\ReferenceItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Baris penerimaan (15 §4.9). `qty_received` satuan baris (yang diterima baik), `qty_received_base`
 * & `qty_rejected_base` satuan dasar; `unit_cost` per satuan dasar dari baris PO (SEC-104).
 * `shelf_life_months`/`line_status` diisi saat periksa, `stock_batch_id` saat posting. String desimal
 * (ADR-101).
 *
 * @property int $id
 * @property int $goods_receipt_id
 * @property int|null $purchase_order_line_id
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $qty_received
 * @property string $qty_received_base
 * @property string $qty_rejected_base
 * @property int|null $reject_reason_id
 * @property string $batch_no
 * @property Carbon|null $expiry_date
 * @property string|null $registration_no_seen
 * @property int|null $stock_bin_id
 * @property string $unit_cost
 * @property string|null $shelf_life_months
 * @property ReceiptLineStatus $line_status
 * @property int|null $stock_batch_id
 * @property-read GoodsReceipt $receipt
 * @property-read Product $product
 * @property-read ProductUnit $productUnit
 * @property-read PurchaseOrderLine|null $purchaseOrderLine
 * @property-read StockBin|null $bin
 * @property-read ReferenceItem|null $rejectReason
 * @property-read StockBatch|null $batch
 * @property-read Collection<int, SerialNumber> $serials
 */
class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'goods_receipt_id' => 'integer',
            'purchase_order_line_id' => 'integer',
            'product_id' => 'integer',
            'product_unit_id' => 'integer',
            'qty_received' => 'decimal:3',
            'qty_received_base' => 'decimal:3',
            'qty_rejected_base' => 'decimal:3',
            'reject_reason_id' => 'integer',
            'expiry_date' => 'date:Y-m-d',
            'stock_bin_id' => 'integer',
            'unit_cost' => 'decimal:4',
            'shelf_life_months' => 'decimal:1',
            'line_status' => ReceiptLineStatus::class,
            'stock_batch_id' => 'integer',
        ];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /** @return BelongsTo<StockBin, $this> */
    public function bin(): BelongsTo
    {
        return $this->belongsTo(StockBin::class, 'stock_bin_id');
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function rejectReason(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'reject_reason_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return HasMany<SerialNumber, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(SerialNumber::class, 'goods_receipt_line_id')->orderBy('id');
    }
}
