<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Nilai setting yang diubah admin. Baca lewat `setting()`; tulis lewat UpdateSettingGroup.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
