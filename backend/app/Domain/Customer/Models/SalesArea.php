<?php

namespace App\Domain\Customer\Models;

use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Region\Models\District;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Area penjualan bertingkat (15 §4.6, FR-A12): wilayah kerja sales dengan daftar kecamatan,
 * supervisor, dan gudang asal default. Tidak ber-scope untuk dilihat (07 §3.4 `sales_areas`);
 * yang ber-scope adalah kios di dalamnya (DataScope::forCustomer).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property int|null $supervisor_employee_id
 * @property int|null $home_stock_location_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read SalesArea|null $parent
 * @property-read Employee|null $supervisor
 * @property-read StockLocation|null $homeStockLocation
 * @property-read Collection<int, District> $districts
 */
class SalesArea extends Model
{
    use SoftDeletes;

    /** Kedalaman maksimum (area → sub-area → sub-sub-area), keputusan T-073 (PA-47). */
    public const MAX_DEPTH = 3;

    /** Maksimum kecamatan per area (batas form, bukan aturan bisnis). */
    public const MAX_DISTRICTS = 100;

    /** Kolom yang diaudit & dibandingkan saat ubah. */
    public const AUDITED = ['code', 'name', 'parent_id', 'supervisor_employee_id', 'home_stock_location_id', 'is_active'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'supervisor_employee_id' => 'integer',
            'home_stock_location_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SalesArea, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class, 'parent_id')->withTrashed();
    }

    /** @return HasMany<SalesArea, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(SalesArea::class, 'parent_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id')->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function homeStockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'home_stock_location_id')->withTrashed();
    }

    /** @return BelongsToMany<District, $this> */
    public function districts(): BelongsToMany
    {
        return $this->belongsToMany(District::class, 'sales_area_districts', 'sales_area_id', 'district_code', 'id', 'code');
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
