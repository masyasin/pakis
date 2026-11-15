<?php

namespace App\Domain\Organization\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Departemen berinduk (04 §2.8, FR-023). Tidak ber-scope data (06 §5).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Department|null $parent
 */
class Department extends Model
{
    use SoftDeletes;

    /** Kolom yang diaudit & dibandingkan saat ubah. */
    public const AUDITED = ['code', 'name', 'parent_id', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /** @return HasMany<Department, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id');
    }
}
