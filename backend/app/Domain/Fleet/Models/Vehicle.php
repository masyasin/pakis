<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Access\DataScope;
use App\Domain\Fleet\Enums\VehicleType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kendaraan minimal (15 §4.3, FR-A05). Kendaraan canvas punya tepat satu lokasi stok `vehicle`
 * (ADR-107) yang disinkronkan SaveVehicle. Kolom armada lain ditambah Paket D.
 *
 * @property int $id
 * @property string $plate_no
 * @property string $name
 * @property VehicleType $vehicle_type
 * @property bool $is_canvas
 * @property int|null $home_location_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Location|null $homeLocation
 * @property-read StockLocation|null $stockLocation
 */
class Vehicle extends Model
{
    use SoftDeletes;

    /** Kolom yang diaudit & dibandingkan saat ubah (tanpa jejak waktu/pembuat). */
    public const AUDITED = ['plate_no', 'name', 'vehicle_type', 'is_canvas', 'home_location_id', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'vehicle_type' => VehicleType::class,
            'is_canvas' => 'boolean',
            'home_location_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Plat disimpan tanpa spasi & huruf besar (15 §4.3): "k 9021 ab" dan "K9021AB" = kendaraan sama,
     * sehingga unique key DB benar-benar mencegah plat ganda.
     */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', $plate));
    }

    /** @return BelongsTo<Location, $this> */
    public function homeLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'home_location_id')->withTrashed();
    }

    /** @return HasOne<StockLocation, $this> */
    public function stockLocation(): HasOne
    {
        return $this->hasOne(StockLocation::class, 'vehicle_id');
    }

    /**
     * Route binding ber-scope (SEC-033): kendaraan di luar scope (lokasi asal, dimensi Stok 15 §6.2)
     * diperlakukan tidak ada → 404.
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
        app(DataScope::class)->forStockByLocation($query, $user, $this->qualifyColumn('home_location_id'));

        return $query->first();
    }
}
