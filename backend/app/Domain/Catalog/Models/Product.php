<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Purchasing\Models\Principal;
use App\Domain\Reference\Models\ReferenceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Produk (FR-A08, 15 §4.4). Penanda perilaku (`tracks_*`, `requires_registration`) disalin dari
 * kategori saat dibuat dan boleh diperketat; melonggarkan butuh alasan tercatat (BR-151). Stok
 * selalu dalam satuan dasar `base_unit_id` (ADR-102).
 *
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property int $product_category_id
 * @property int|null $principal_id
 * @property string|null $brand
 * @property string|null $active_ingredient
 * @property int|null $formulation_id
 * @property string|null $package_size
 * @property int $base_unit_id
 * @property string|null $description
 * @property string|null $usage_note
 * @property string|null $photo_path
 * @property bool $tracks_batch
 * @property bool $tracks_expiry
 * @property bool $tracks_serial
 * @property bool $requires_registration
 * @property bool $is_restricted
 * @property string|null $restricted_note
 * @property int|null $warranty_months
 * @property int|null $min_shelf_life_months
 * @property bool $is_sellable
 * @property bool $is_purchasable
 * @property ProductStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ProductCategory $category
 * @property-read ReferenceItem $baseUnit
 * @property-read ReferenceItem|null $formulation
 * @property-read Principal|null $principal
 */
class Product extends Model
{
    use SoftDeletes;

    /** Disk foto produk: publik (bukan data pribadi), lewat SecureUpload (T-070). */
    public const PHOTO_DISK = 'public';

    /** Penanda perilaku yang disalin dari kategori (BR-151): kolom produk => kolom kategori. */
    public const FLAGS = [
        'tracks_batch' => 'requires_batch',
        'tracks_expiry' => 'requires_expiry',
        'tracks_serial' => 'requires_serial',
        'requires_registration' => 'requires_registration',
    ];

    /** Kolom yang diaudit (selain foto, yang dicatat sebagai ada/ganti). */
    public const AUDITED = [
        'sku', 'name', 'product_category_id', 'principal_id', 'brand', 'active_ingredient', 'formulation_id',
        'package_size', 'base_unit_id', 'description', 'usage_note', 'tracks_batch', 'tracks_expiry', 'tracks_serial',
        'requires_registration', 'is_restricted', 'restricted_note', 'warranty_months', 'min_shelf_life_months',
        'is_sellable', 'is_purchasable', 'status',
    ];

    /** Semua kolom diisi Action lewat forceFill dari data tervalidasi. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'tracks_batch' => 'boolean',
            'tracks_expiry' => 'boolean',
            'tracks_serial' => 'boolean',
            'requires_registration' => 'boolean',
            'is_restricted' => 'boolean',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'product_category_id' => 'integer',
            'principal_id' => 'integer',
            'formulation_id' => 'integer',
            'base_unit_id' => 'integer',
            'warranty_months' => 'integer',
            'min_shelf_life_months' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id')->withTrashed();
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'base_unit_id');
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function formulation(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'formulation_id');
    }

    /** @return HasMany<ProductUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** @return BelongsTo<Principal, $this> prinsipal/merek (T-072), termasuk yang sudah dinonaktifkan */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class)->withTrashed();
    }

    /** @return HasMany<ProductRegistration, $this> izin edar, termasuk yang habis/dicabut (riwayat perpanjangan) */
    public function registrations(): HasMany
    {
        return $this->hasMany(ProductRegistration::class);
    }

    /** URL foto relatif (`/storage/...`, lolos CSP `img-src 'self'`), atau null. */
    public function photoUrl(): ?string
    {
        if ($this->photo_path === null || $this->photo_path === '') {
            return null;
        }

        return parse_url(Storage::disk(self::PHOTO_DISK)->url($this->photo_path), PHP_URL_PATH) ?: null;
    }

    /** @return array<string, mixed> nilai audit dengan enum sebagai string */
    public function audited(): array
    {
        $values = $this->only(self::AUDITED);
        $values['status'] = $this->status->value;

        return $values;
    }
}
