<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Baris rekap bulanan (T-039, BR-072): agregasi `attendance_days` per pegawai, BACA SAJA.
 *
 * Model terpisah (tabel sama, kunci `employee_id`) karena mesin listing selalu menambah urutan
 * pemecah seri pada primary key model; pada query `GROUP BY employee_id` hanya `employee_id` yang
 * sah diurutkan (ONLY_FULL_GROUP_BY). Kolom selain `employee_id` adalah alias agregat dari
 * AttendanceSummaryListing.
 *
 * @property int $employee_id
 * @property string|null $full_name
 * @property string|null $employee_number
 * @property int|string $scheduled_days
 * @property int|string $present_days
 * @property int|string $late_count
 * @property int|string $late_minutes_total
 * @property int|string $early_leave_count
 * @property int|string $no_checkout_days
 * @property int|string $absent_days
 * @property int|string $off_days
 * @property float|string|null $attendance_pct
 * @property string|null $last_location_id id lokasi snapshot hari terakhir di bulan itu
 * @property-read Employee|null $employee
 */
class AttendanceSummary extends Model
{
    protected $table = 'attendance_days';

    protected $primaryKey = 'employee_id';

    public $incrementing = false;

    public $timestamps = false;

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Rekap tidak pernah ditulis: datanya turunan `attendance_days`. */
    public function save(array $options = []): bool
    {
        throw new LogicException('AttendanceSummary hanya untuk dibaca.');
    }
}
