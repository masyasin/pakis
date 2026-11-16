<?php

namespace App\Domain\Approval\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Aturan approval per jenis dokumen (15 §4.2, BR-153(1)). Rentang nominal: `min_amount` inklusif,
 * `max_amount` eksklusif; NULL = tanpa batas. Aturan yang pernah dipakai permintaan hanya bisa
 * dinonaktifkan (FK `apr_rule_fk` juga mencegah penghapusan).
 *
 * @property int $id
 * @property string $document_type
 * @property string $name
 * @property string|null $min_amount
 * @property string|null $max_amount
 * @property array<string, list<int|string>>|null $conditions
 * @property int $priority
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ApprovalRuleStep> $steps
 */
class ApprovalRule extends Model
{
    public const AUDITED = ['document_type', 'name', 'min_amount', 'max_amount', 'conditions', 'priority', 'is_active'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'conditions' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<ApprovalRuleStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalRuleStep::class)->orderBy('step_order');
    }

    /** @return HasMany<ApprovalRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function isUsed(): bool
    {
        return $this->requests()->exists();
    }
}
