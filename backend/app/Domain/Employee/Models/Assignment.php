<?php

namespace App\Domain\Employee\Models;

use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan pegawai (04 §2.9, BR-051). Maksimal satu penugasan UTAMA aktif per pegawai
 * (index `assignments_one_active_primary`). Mode absen efektif = `attendance_mode` ?? jabatan
 * (BR-042). Mutasi & pengakhiran di T-025.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $position_id
 * @property int $location_id
 * @property int|null $supervisor_employee_id
 * @property AttendanceMode|null $attendance_mode
 * @property bool $is_primary
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $employee
 * @property-read Position|null $position
 * @property-read Location|null $location
 * @property-read Employee|null $supervisor
 */
class Assignment extends Model
{
    public const AUDITED = ['employee_id', 'position_id', 'location_id', 'supervisor_employee_id', 'attendance_mode', 'is_primary', 'start_date', 'end_date', 'note'];

    protected $fillable = self::AUDITED;

    protected function casts(): array
    {
        return [
            'attendance_mode' => AttendanceMode::class,
            'is_primary' => 'boolean',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'employee_id' => 'integer',
            'position_id' => 'integer',
            'location_id' => 'integer',
            'supervisor_employee_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Termasuk yang terhapus: riwayat penugasan tetap menampilkan nama jabatan lama.
     *
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class)->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    /** @return BelongsTo<Employee, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_employee_id')->withTrashed();
    }

    /** BR-042: mode absen efektif. */
    public function effectiveAttendanceMode(): ?AttendanceMode
    {
        return $this->attendance_mode ?? $this->position?->attendance_mode;
    }
}
