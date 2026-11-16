<?php

namespace App\Domain\Customer\Models;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Employee\Models\Employee;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\Village;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kios / pelanggan (15 §4.6, FR-A13). T-073 = data inti; verifikasi, kontak, limit, foto toko, dan
 * kelayakan pembeli terbatas diisi T-074, `price_list_id` T-075.
 *
 * `sales_area_id` & `assigned_sales_employee_id` adalah CACHE penugasan aktif (BR-167): hanya diubah
 * ReassignCustomers / AssignmentApplier, tidak pernah dari form.
 *
 * @property int $id
 * @property string $code
 * @property string $store_name
 * @property string $owner_name
 * @property string $owner_phone
 * @property Carbon|null $owner_phone_verified_at
 * @property int $customer_type_id
 * @property string|null $address
 * @property string|null $village_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $photo_path
 * @property int $sales_area_id
 * @property int|null $assigned_sales_employee_id
 * @property int|null $price_list_id
 * @property int|null $home_stock_location_id
 * @property string $credit_limit
 * @property bool $credit_limit_active
 * @property int $term_days
 * @property CustomerStatus $status
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read ReferenceItem|null $customerType
 * @property-read Village|null $village
 * @property-read SalesArea|null $salesArea
 * @property-read Employee|null $assignedSales
 * @property-read StockLocation|null $homeStockLocation
 * @property-read CustomerAssignment|null $openAssignment
 */
class Customer extends Model
{
    use SoftDeletes;

    /** Kolom profil yang diubah form T-073 dan diaudit (tanpa cache penugasan, limit, verifikasi). */
    public const PROFILE = ['store_name', 'owner_name', 'owner_phone', 'customer_type_id', 'address', 'village_code', 'latitude', 'longitude', 'home_stock_location_id', 'term_days'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'owner_phone_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'customer_type_id' => 'integer',
            'sales_area_id' => 'integer',
            'assigned_sales_employee_id' => 'integer',
            'price_list_id' => 'integer',
            'home_stock_location_id' => 'integer',
            'credit_limit' => 'decimal:2',
            'credit_limit_active' => 'boolean',
            'term_days' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /** @return BelongsTo<ReferenceItem, $this> */
    public function customerType(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'customer_type_id');
    }

    /** @return BelongsTo<Village, $this> */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
    }

    /** @return BelongsTo<SalesArea, $this> */
    public function salesArea(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class)->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function assignedSales(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_sales_employee_id')->withTrashed();
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function homeStockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'home_stock_location_id')->withTrashed();
    }

    /** @return HasMany<CustomerAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class);
    }

    /**
     * Penugasan terbuka (`end_date` NULL): yang berlaku, atau yang tertunda bila mulai nanti.
     *
     * @return HasOne<CustomerAssignment, $this>
     */
    public function openAssignment(): HasOne
    {
        return $this->hasOne(CustomerAssignment::class)->whereNull('end_date');
    }

    /** @return array<string, mixed> nilai profil untuk audit (tanpa jejak waktu/pembuat) */
    public function audited(): array
    {
        $values = $this->only(self::PROFILE);
        $values['code'] = $this->code;

        return $values;
    }

    /**
     * Route binding ber-scope (SEC-033, 15 §6.2): kios di luar scope Pelanggan = tidak ada → 404.
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
        app(DataScope::class)->forCustomer($query, $user, $this->qualifyColumn('id'));

        return $query->first();
    }
}
