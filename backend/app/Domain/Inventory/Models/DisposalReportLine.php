<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Support\Money\Qty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris berita acara: satu batch karantina/kedaluwarsa + qty satuan dasar + biaya satuan (HPP saat
 * disimpan) untuk nilai BA & nominal approval.
 *
 * @property int $id
 * @property int $disposal_report_id
 * @property int $stock_batch_id
 * @property int $product_id
 * @property string $qty_base
 * @property string $unit_cost
 * @property-read DisposalReport $report
 * @property-read StockBatch $batch
 * @property-read Product $product
 */
class DisposalReportLine extends Model
{
    protected $table = 'disposal_report_lines';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'disposal_report_id' => 'integer',
            'stock_batch_id' => 'integer',
            'product_id' => 'integer',
        ];
    }

    /** @return BelongsTo<DisposalReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(DisposalReport::class, 'disposal_report_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function qty(): Qty
    {
        return Qty::of($this->qty_base);
    }
}
