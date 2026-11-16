<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Units\ProductUnitLike;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satuan produk (BR-150): "1 satuan ini = factor satuan dasar". Satuan dasar faktor 1 (CHECK
 * `pu_factor_chk`). Faktor yang sudah dipakai dokumen tidak bisa diubah (`UNIT_FACTOR_LOCKED`).
 *
 * @property int $id
 * @property int $product_id
 * @property int $unit_id
 * @property string $factor
 * @property bool $is_base
 * @property bool $is_default_sales
 * @property bool $is_default_purchase
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Product $product
 * @property-read ReferenceItem $unit
 */
class ProductUnit extends Model implements ProductUnitLike
{
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'unit_id' => 'integer',
            // String desimal kanonik ("24.000"), bukan float (ADR-101).
            'factor' => 'decimal:3',
            'is_base' => 'boolean',
            'is_default_sales' => 'boolean',
            'is_default_purchase' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'unit_id');
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function conversionFactor(): string
    {
        return $this->factor;
    }

    public function isIntegerOnly(): bool
    {
        return ($this->unit->meta['integer_only'] ?? false) === true;
    }

    public function unitLabel(): string
    {
        return $this->unit->name;
    }

    /** Faktor untuk tampilan id-ID tanpa nol di belakang koma: "24.000" → "24", "0.500" → "0,5". */
    public function factorLabel(): string
    {
        [$whole, $fraction] = array_pad(explode('.', $this->factor), 2, '');
        $fraction = rtrim($fraction, '0');

        // Pengelompokan ribuan berbasis string: domain Catalog bebas float (NoFloatInDomainTest).
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', ltrim($whole, '0') ?: '0');

        return $grouped.($fraction !== '' ? ','.$fraction : '');
    }
}
