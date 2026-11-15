<?php

namespace App\Domain\Device\Models;

use App\Domain\Access\DataScope;
use App\Domain\Device\Enums\DeviceStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perangkat Pakis Pro (04 §2.12, BR-040). Scope data mengikuti pegawai pemilik akun
 * (`users.employee_id`, 06 §5): perangkat akun tanpa pegawai hanya terlihat scope `all`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $device_uid
 * @property string|null $model
 * @property string|null $os_version
 * @property string|null $app_version
 * @property DeviceStatus $status
 * @property string|null $request_reason
 * @property int|null $token_id
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class Device extends Model
{
    /** Kolom yang diaudit (tanpa `device_uid`: pengenal teknis, cukup model/OS di audit). */
    public const AUDITED = ['status', 'model', 'os_version', 'app_version', 'request_reason'];

    protected $fillable = ['user_id', 'device_uid', 'model', 'os_version', 'app_version', 'status', 'request_reason'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Hanya perangkat milik akun dalam scope data pengguna (lewat `users.employee_id`).
     *
     * @param  Builder<Device>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        // Scope `all`: tanpa batas (hindari subquery yang tidak perlu).
        if (app(DataScope::class)->visibleEmployeeIds($viewer) === null) {
            return;
        }

        $query->whereIn($query->qualifyColumn('user_id'), app(DataScope::class)->apply(
            User::withTrashed()->select('users.id'),
            $viewer,
            'users.employee_id',
        ));
    }

    /**
     * Route binding ber-scope (SEC-033): perangkat di luar scope = tidak ada → 404.
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

        return $this->resolveRouteBindingQuery($this->newQuery()->visibleTo($user), $value, $field)->first();
    }

    public function label(): string
    {
        return trim(($this->model ?? 'Perangkat').' · '.($this->os_version ?? '-'));
    }
}
