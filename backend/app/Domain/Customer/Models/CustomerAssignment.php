<?php

namespace App\Domain\Customer\Models;

use App\Domain\Employee\Models\Employee;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat penugasan kios ke area & sales (15 §4.6, BR-167). Tepat satu baris terbuka
 * (`end_date` NULL) per kios, dijaga index unik `ca_one_active_unique` atas kolom `active_key`.
 * Baris terbuka bisa bertanggal mulai nanti (pengalihan tertunda); saat itu baris sebelumnya sudah
 * ditutup `end_date = mulai − 1`, sedangkan cache di `customers` baru berubah pada hari H.
 *
 * @property int $id
 * @property int $customer_id
 * @property int $sales_area_id
 * @property int|null $sales_employee_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string|null $reason
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property-read SalesArea|null $salesArea
 * @property-read Employee|null $salesEmployee
 */
class CustomerAssignment extends Model
{
    protected $guarded = ['id', 'active_key'];

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'sales_area_id' => 'integer',
            'sales_employee_id' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /** @return BelongsTo<SalesArea, $this> */
    public function salesArea(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class)->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function salesEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sales_employee_id')->withTrashed();
    }
}
