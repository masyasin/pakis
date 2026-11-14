<?php

namespace App\Domain\Reference\Models;

use App\Domain\Reference\Enums\ReferenceGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Satu pilihan dalam grup referensi (04 §2.6). Item nonaktif tidak muncul di pilihan baru, tetapi
 * tetap tampil di data lama yang sudah memakainya (BR-026).
 *
 * @property int $id
 * @property string $group_code
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 * @property bool $is_system
 * @property array<string, mixed>|null $meta
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReferenceItem extends Model
{
    protected $table = 'reference_items';

    protected $fillable = ['group_code', 'code', 'name', 'description', 'sort_order', 'is_active', 'is_system', 'meta', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function group(): ?ReferenceGroup
    {
        return ReferenceGroup::tryFrom($this->group_code);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForGroup(Builder $query, ReferenceGroup $group): void
    {
        $query->where('group_code', $group->value);
    }
}
