<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Enums\PriceVersionStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versi daftar harga (15 §4.7, ADR-108). Append-only: hanya `draft` yang itemnya boleh diubah
 * (Policy/Action + trigger `price_list_items_lock_*`, SEC-106). Rentang berlaku
 * `effective_from ≤ t < effective_to` (UTC); `effective_to` diisi saat versi berikutnya terbit.
 *
 * @property int $id
 * @property int $price_list_id
 * @property int $version_no
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property PriceVersionStatus $status
 * @property string|null $note
 * @property int|null $approval_request_id
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property int|null $copied_from_version_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceList $priceList
 */
class PriceListVersion extends Model
{
    protected $table = 'price_list_versions';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'price_list_id' => 'integer',
            'version_no' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'status' => PriceVersionStatus::class,
            'approval_request_id' => 'integer',
            'published_by' => 'integer',
            'published_at' => 'datetime',
            'copied_from_version_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class, 'price_list_version_id');
    }

    public function isDraft(): bool
    {
        return $this->status === PriceVersionStatus::Draft;
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'price_list_id' => $this->price_list_id,
            'version_no' => $this->version_no,
            'effective_from' => $this->effective_from->toIso8601String(),
            'effective_to' => $this->effective_to?->toIso8601String(),
            'status' => $this->status->value,
            'note' => $this->note,
        ];
    }
}
