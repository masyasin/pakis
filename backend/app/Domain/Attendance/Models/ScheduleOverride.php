<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employee\Models\Employee;
use App\Support\Authorization\ScopedRouteBinding;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pengecualian jadwal satu tanggal (04 §2.13, BR-061/BR-062): ganti shift, atau libur bila
 * `shift_id` NULL. Satu baris per (pegawai, tanggal). Route `{override}` ber-scope data lewat
 * pegawainya: di luar scope → 404 (SEC-033).
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $date
 * @property int|null $shift_id
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $employee
 * @property-read Shift|null $shift
 */
class ScheduleOverride extends Model
{
    use ScopedRouteBinding;

    public const AUDITED = ['employee_id', 'date', 'shift_id', 'note'];

    protected $fillable = [...self::AUDITED, 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'employee_id' => 'integer',
            'shift_id' => 'integer',
        ];
    }

    public static function scopedEmployeeColumn(): string
    {
        return 'employee_id';
    }

    public function isDayOff(): bool
    {
        return $this->shift_id === null;
    }

    /** @return array<string, mixed> */
    public function auditValues(): array
    {
        return [
            'employee_id' => $this->employee_id,
            'date' => $this->date->toDateString(),
            'shift_id' => $this->shift_id,
            'note' => $this->note,
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Termasuk shift terhapus (soft delete): pengecualian lama tetap menampilkan kode shift-nya.
     *
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }
}
