<?php

namespace App\Domain\Approval\Models;

use App\Domain\Approval\Policies\ApprovalDelegationPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delegasi bertanggal (15 §4.2, BR-153(7)). Berlaku bila `is_active` dan tanggal lokal hari ini di
 * `start_date`–`end_date` (inklusif). Dicabut = `is_active=false` (riwayat tetap ada).
 *
 * @property int $id
 * @property int $delegator_user_id
 * @property int $delegate_user_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property list<string>|null $document_types
 * @property string $reason
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $delegator
 * @property-read User|null $delegate
 */
class ApprovalDelegation extends Model
{
    public const AUDITED = ['delegator_user_id', 'delegate_user_id', 'start_date', 'end_date', 'document_types', 'reason', 'is_active'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'delegator_user_id' => 'integer',
            'delegate_user_id' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'document_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_user_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_user_id')->withTrashed();
    }

    /**
     * Delegasi yang berlaku pada tanggal lokal `$date` (Y-m-d).
     *
     * @param  Builder<ApprovalDelegation>  $query
     * @return Builder<ApprovalDelegation>
     */
    public function scopeActiveOn(Builder $query, string $date): Builder
    {
        return $query->where('approval_delegations.is_active', true)
            ->where('approval_delegations.start_date', '<=', $date)
            ->where('approval_delegations.end_date', '>=', $date);
    }

    public function covers(string $documentType): bool
    {
        return $this->document_types === null || in_array($documentType, $this->document_types, true);
    }

    /**
     * Route `{delegation}`: hanya pemberi, penerima, atau pemegang `approvals.configure`; selain itu 404.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return null;
        }
        $delegation = self::query()->where($field ?? $this->getRouteKeyName(), $value)->first();

        return $delegation !== null && ApprovalDelegationPolicy::sees($user, $delegation) ? $delegation : null;
    }

    /** `active` (berlaku), `scheduled` (belum mulai), `expired` (lewat), `revoked` (dicabut). */
    public function stateOn(string $date): string
    {
        if (! $this->is_active) {
            return 'revoked';
        }
        if ($this->start_date->toDateString() > $date) {
            return 'scheduled';
        }

        return $this->end_date->toDateString() < $date ? 'expired' : 'active';
    }
}
