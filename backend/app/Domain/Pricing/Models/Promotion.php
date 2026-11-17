<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Pricing\Enums\PromotionFunding;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Enums\PromotionType;
use App\Domain\Purchasing\Models\Principal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promo (15 §4.7, FR-A20, BR-169). Hanya `draft` yang bisa diubah; `active` hanya bisa diakhiri.
 * Perhitungan ada di PromotionEngine (murni) lewat PromotionData.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PromotionType $type
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $priority
 * @property bool $is_stackable
 * @property list<string> $channels
 * @property string|null $budget_amount
 * @property string $budget_used
 * @property PromotionFunding $funded_by
 * @property int|null $principal_id
 * @property string|null $principal_share_pct
 * @property PromotionStatus $status
 * @property int|null $approval_request_id
 * @property string|null $description
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Principal|null $principal
 */
class Promotion extends Model
{
    public const CHANNELS = ['office', 'sales', 'mitra', 'pos'];

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'priority' => 'integer',
            'is_stackable' => 'boolean',
            'channels' => 'array',
            'budget_amount' => 'decimal:2',
            'budget_used' => 'decimal:2',
            'funded_by' => PromotionFunding::class,
            'principal_id' => 'integer',
            'principal_share_pct' => 'decimal:4',
            'status' => PromotionStatus::class,
            'approval_request_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Principal, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class)->withTrashed();
    }

    /** @return HasMany<PromotionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class, 'promotion_id');
    }

    /** @return HasMany<PromotionScope, $this> */
    public function scopes(): HasMany
    {
        return $this->hasMany(PromotionScope::class, 'promotion_id');
    }

    /** @return HasMany<PromotionTier, $this> */
    public function tiers(): HasMany
    {
        return $this->hasMany(PromotionTier::class, 'promotion_id')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function audited(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'priority' => $this->priority,
            'is_stackable' => $this->is_stackable,
            'channels' => $this->channels,
            'budget_amount' => $this->budget_amount,
            'funded_by' => $this->funded_by->value,
            'principal_id' => $this->principal_id,
            'principal_share_pct' => $this->principal_share_pct,
            'status' => $this->status->value,
        ];
    }
}
