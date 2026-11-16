<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Customer\Models\SalesArea;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Pricing\Enums\PriceListKind;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Daftar harga (15 §4.7, FR-A19). Harga sebenarnya ada di versinya (append-only, ADR-108). Hanya
 * boleh satu list `default` (UNIQUE generated `default_key`). `kind` tidak bisa diubah setelah
 * dibuat: kios/area/toko yang menunjuk list ini bergantung pada jenisnya.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PriceListKind $kind
 * @property int|null $sales_area_id
 * @property int|null $stock_location_id
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SalesArea|null $salesArea
 * @property-read StockLocation|null $stockLocation
 */
class PriceList extends Model
{
    public const AUDITED = ['code', 'name', 'kind', 'sales_area_id', 'stock_location_id', 'description', 'is_active'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'kind' => PriceListKind::class,
            'sales_area_id' => 'integer',
            'stock_location_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesArea, $this> */
    public function salesArea(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class)->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class)->withTrashed();
    }

    /** @return HasMany<PriceListVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PriceListVersion::class);
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [...$this->only(self::AUDITED), 'kind' => $this->kind->value];
    }
}
