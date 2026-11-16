<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\BinZone;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rak/bin di lokasi stok gudang/toko (15 §4.3, FR-A04).
 *
 * @property int $id
 * @property int $stock_location_id
 * @property string $code
 * @property string|null $name
 * @property BinZone $zone
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockLocation $stockLocation
 */
class StockBin extends Model
{
    /**
     * Rak karantina bawaan setiap gudang/toko (15 §4.3). Tujuan otomatis karantina kedaluwarsa
     * (T-078) & karantina manual (T-084), jadi kode, zona, dan status aktifnya dikunci.
     */
    public const QUARANTINE_CODE = 'Q-01';

    /** Kolom yang diaudit & dibandingkan saat ubah (tanpa jejak waktu/pembuat). */
    public const AUDITED = ['stock_location_id', 'code', 'name', 'zone', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'stock_location_id' => 'integer',
            'zone' => BinZone::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class)->withTrashed();
    }

    public function isDefaultQuarantine(): bool
    {
        return $this->code === self::QUARANTINE_CODE;
    }

    /**
     * Route binding ber-scope (SEC-033): rak di lokasi stok di luar scope → 404.
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
        app(DataScope::class)->forStock($query, $user, $this->qualifyColumn('stock_location_id'));

        return $query->first();
    }
}
