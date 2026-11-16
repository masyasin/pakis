<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Enums\StorageZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Kategori produk bertingkat (FR-A06, BR-151, 15 §4.4), maks 3 tingkat. Penanda perilaku disalin
 * ke produk saat produk dibuat (T-070) dan boleh diperketat per produk.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property CategoryKind $kind
 * @property bool $requires_batch
 * @property bool $requires_expiry
 * @property bool $requires_serial
 * @property bool $requires_registration
 * @property StorageZone $default_bin_zone
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ProductCategory|null $parent
 */
class ProductCategory extends Model
{
    use SoftDeletes;

    /** Kolom yang diaudit & diisi dari form (ProductCategoryRequest). */
    public const AUDITED = [
        'code', 'name', 'parent_id', 'kind', 'requires_batch', 'requires_expiry', 'requires_serial',
        'requires_registration', 'default_bin_zone', 'sort_order', 'is_active',
    ];

    /** Kedalaman maksimum pohon kategori (kriteria T-069 / FR-A06), bukan parameter bisnis. */
    public const MAX_DEPTH = 3;

    protected $fillable = self::AUDITED;

    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'default_bin_zone' => StorageZone::class,
            'requires_batch' => 'boolean',
            'requires_expiry' => 'boolean',
            'requires_serial' => 'boolean',
            'requires_registration' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<ProductCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return array<string, mixed> nilai audit dengan enum sebagai string */
    public function audited(): array
    {
        $values = $this->only(self::AUDITED);
        $values['kind'] = $this->kind->value;
        $values['default_bin_zone'] = $this->default_bin_zone->value;

        return $values;
    }
}
