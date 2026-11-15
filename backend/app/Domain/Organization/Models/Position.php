<?php

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\AttendanceMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Jabatan (04 §2.8, FR-024, BR-042). Tidak ber-scope data (06 §5).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $department_id
 * @property int|null $parent_position_id
 * @property int $level
 * @property AttendanceMode $attendance_mode
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Department|null $department
 * @property-read Position|null $parent
 */
class Position extends Model
{
    use SoftDeletes;

    /** Kolom yang diaudit & dibandingkan saat ubah. */
    public const AUDITED = ['code', 'name', 'department_id', 'parent_position_id', 'level', 'attendance_mode', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected $attributes = [
        'level' => 5,
        'attendance_mode' => 'location',
    ];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'parent_position_id' => 'integer',
            'level' => 'integer',
            'attendance_mode' => AttendanceMode::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'parent_position_id');
    }
}
