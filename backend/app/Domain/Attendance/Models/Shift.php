<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Support\ShiftScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Shift kerja (04 §2.13, FR-031, BR-060). `start_time`/`end_time` = jam dinding lokal `HH:MM:SS`;
 * shift `crosses_midnight` berakhir di hari berikutnya.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property bool $crosses_midnight
 * @property int $late_tolerance_min
 * @property int $checkin_open_min
 * @property int $checkout_close_min
 * @property string $color
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Shift extends Model
{
    use SoftDeletes;

    /** Kolom yang diaudit & dibandingkan saat ubah (tanpa jejak waktu/pembuat). */
    public const AUDITED = ['code', 'name', 'start_time', 'end_time', 'crosses_midnight', 'late_tolerance_min', 'checkin_open_min', 'checkout_close_min', 'color', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected $attributes = [
        'crosses_midnight' => false,
        'late_tolerance_min' => 0,
        'checkin_open_min' => 120,
        'checkout_close_min' => 360,
        'color' => '#2F6B3B',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'late_tolerance_min' => 'integer',
            'checkin_open_min' => 'integer',
            'checkout_close_min' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** `07:30:00` → `07:30` (nilai input form & tampilan). */
    public static function hm(string $time): string
    {
        return substr($time, 0, 5);
    }

    /**
     * Route binding ber-scope (SEC-033): shift di luar scope pengguna diperlakukan tidak ada → 404.
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

        $query = $this->resolveRouteBindingQuery($this->newQuery(), $value, $field);
        app(ShiftScope::class)->apply($query, $user, $this->qualifyColumn('id'));

        return $query->first();
    }
}
