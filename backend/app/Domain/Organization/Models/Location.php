<?php

namespace App\Domain\Organization\Models;

use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\Village;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lokasi kerja (04 §2.8, FR-022, BR-041).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $location_type_id
 * @property string|null $address
 * @property string|null $village_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $attendance_radius_m
 * @property string|null $phone
 * @property int|null $head_employee_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ReferenceItem|null $type
 * @property-read Village|null $village
 * @property-read Employee|null $head
 */
class Location extends Model
{
    use SoftDeletes;

    /** Kolom yang diaudit & dibandingkan saat ubah (tanpa jejak waktu/pembuat). */
    public const AUDITED = ['code', 'name', 'location_type_id', 'address', 'village_code', 'latitude', 'longitude', 'attendance_radius_m', 'phone', 'head_employee_id', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            // String desimal, bukan float: koordinat 7 digit tidak boleh bergeser karena pembulatan.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'attendance_radius_m' => 'integer',
            'head_employee_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'location_type_id');
    }

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
    }

    /**
     * Kepala lokasi (T-024). Menentukan scope `location` (06 §5): kepala melihat pegawai lokasinya.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id')->withTrashed();
    }

    /** BR-041: tanpa koordinat, lokasi tidak bisa dipakai validasi radius absen. */
    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Radius efektif: milik lokasi, atau `attendance.default_radius_m`. */
    public function effectiveRadius(): int
    {
        return $this->attendance_radius_m ?? (int) setting('attendance.default_radius_m');
    }

    /**
     * Route binding ber-scope (SEC-033): lokasi di luar scope pengguna diperlakukan tidak ada → 404.
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
        app(LocationScope::class)->apply($query, $user, $this->qualifyColumn('id'));

        return $query->first();
    }
}
