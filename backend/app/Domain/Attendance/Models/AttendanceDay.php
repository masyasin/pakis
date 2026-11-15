<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use App\Support\Authorization\ScopedRouteBinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ringkasan absen satu pegawai pada satu `work_date` (tanggal lokal, 04 §2.13). Dibuat/diperbarui
 * oleh RecordAttendance (T-036) lalu dihitung ulang oleh resolver status (DayStatusResolver,
 * T-037). Snapshot lokasi/jabatan/departemen = penugasan utama pada tanggal itu (BR-068). Route
 * `{day}` ber-scope lewat pegawai (06 §5): di luar scope → 404.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $work_date
 * @property int|null $location_id
 * @property int|null $position_id
 * @property int|null $department_id
 * @property int|null $shift_id
 * @property CarbonImmutable|null $scheduled_start
 * @property CarbonImmutable|null $scheduled_end
 * @property int|null $check_in_log_id
 * @property int|null $check_out_log_id
 * @property CarbonImmutable|null $check_in_at
 * @property CarbonImmutable|null $check_out_at
 * @property int $late_minutes
 * @property int $early_leave_minutes
 * @property int $work_minutes
 * @property AttendanceStatus $status
 * @property bool $is_corrected
 * @property bool $is_offline
 * @property bool $has_anomaly
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shift|null $shift
 * @property-read Location|null $location
 * @property-read AttendanceLog|null $checkInLog
 * @property-read AttendanceLog|null $checkOutLog
 * @property-read Employee|null $employee
 */
class AttendanceDay extends Model
{
    use ScopedRouteBinding;

    protected $guarded = ['id'];

    protected $attributes = [
        'late_minutes' => 0,
        'early_leave_minutes' => 0,
        'work_minutes' => 0,
        'is_corrected' => false,
        'is_offline' => false,
        'has_anomaly' => false,
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'status' => AttendanceStatus::class,
            'is_corrected' => 'boolean',
            'is_offline' => 'boolean',
            'has_anomaly' => 'boolean',
            'resolved_at' => 'datetime',
            'employee_id' => 'integer',
            'location_id' => 'integer',
            'position_id' => 'integer',
            'department_id' => 'integer',
            'shift_id' => 'integer',
            'check_in_log_id' => 'integer',
            'check_out_log_id' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'work_minutes' => 'integer',
        ];
    }

    public static function scopedEmployeeColumn(): string
    {
        return 'employee_id';
    }

    /**
     * Termasuk shift terhapus: hari lampau tetap menampilkan shift aslinya.
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /** @return BelongsTo<AttendanceLog, $this> */
    public function checkInLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'check_in_log_id');
    }

    /** @return BelongsTo<AttendanceLog, $this> */
    public function checkOutLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceLog::class, 'check_out_log_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
