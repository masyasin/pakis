<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\SerialInputMethod;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Purchasing\Models\GoodsReceiptLine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nomor seri produk alat (15 §4.9, BR-144): unik per produk (`serial_product_no_unique`), huruf besar
 * tanpa spasi. Dibuat saat input di baris penerimaan dengan `stock_batch_id` NULL; terisi saat GR
 * diposting (PA-65). Daftar nomor seri hanya menampilkan yang sudah diposting. `sold_at`/`customer_id`
 * diisi Paket B.
 *
 * @property int $id
 * @property int $product_id
 * @property string $serial_no
 * @property int|null $goods_receipt_line_id
 * @property int|null $stock_batch_id
 * @property SerialStatus $status
 * @property SerialInputMethod $input_method
 * @property string|null $label_photo_path
 * @property Carbon|null $sold_at
 * @property int|null $customer_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read GoodsReceiptLine|null $receiptLine
 * @property-read StockBatch|null $batch
 */
class SerialNumber extends Model
{
    protected $table = 'serial_numbers';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'goods_receipt_line_id' => 'integer',
            'stock_batch_id' => 'integer',
            'status' => SerialStatus::class,
            'input_method' => SerialInputMethod::class,
            'sold_at' => 'datetime',
            'customer_id' => 'integer',
        ];
    }

    /** Normalisasi nomor seri: huruf besar, tanpa spasi (BR-144). */
    public static function normalize(string $raw): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $raw));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<GoodsReceiptLine, $this> */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }
}
