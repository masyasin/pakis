<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Access\DataScope;
use App\Domain\Fleet\Models\Vehicle;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lokasi stok (15 §4.3, FR-A03, ADR-107): satu-satunya tempat stok berada.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property StockLocationType $type
 * @property int|null $location_id
 * @property int|null $vehicle_id
 * @property bool $is_sellable
 * @property bool $is_system
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Location|null $location
 * @property-read Vehicle|null $vehicle
 */
class StockLocation extends Model
{
    use SoftDeletes;

    /** Kode lokasi sistem barang dalam perjalanan (15 §4.3 seed). */
    public const TRANSIT_CODE = 'TRANSIT';

    /** Kolom yang diaudit & dibandingkan saat ubah (tanpa jejak waktu/pembuat). */
    public const AUDITED = ['code', 'name', 'type', 'location_id', 'vehicle_id', 'is_sellable', 'is_active'];

    protected $fillable = [...self::AUDITED, 'is_system', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'type' => StockLocationType::class,
            'location_id' => 'integer',
            'vehicle_id' => 'integer',
            'is_sellable' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Kode lokasi stok kendaraan canvas: dibuat otomatis dari plat (maks 15 + 4 = 19 karakter). */
    public static function vehicleCode(string $plateNo): string
    {
        return 'KDR-'.$plateNo;
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    /** @return HasMany<StockBin, $this> */
    public function bins(): HasMany
    {
        return $this->hasMany(StockBin::class);
    }

    /** Lokasi sistem (TRANSIT) tidak bisa diubah/dinonaktifkan dari layar. */
    public function isProtected(): bool
    {
        return $this->is_system;
    }

    /**
     * Route binding ber-scope (SEC-033, 15 §6.2): lokasi stok di luar scope = tidak ada → 404.
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
        app(DataScope::class)->forStock($query, $user, $this->qualifyColumn('id'));

        return $query->first();
    }
}
