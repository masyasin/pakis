<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Employee\Models\Employee;
use App\Support\Authorization\ScopedRouteBinding;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pola shift mingguan pegawai (04 §2.13, BR-061) yang berlaku MULAI `effective_from` sampai ada
 * pola dengan tanggal lebih baru. Pola lama tidak pernah dihapus/ditimpa oleh pola baru, sehingga
 * jadwal tanggal lampau tetap bisa dihitung ulang dengan pola yang berlaku saat itu.
 * Kolom hari NULL = libur pada hari itu (bukan "tidak dijadwalkan").
 *
 * @property int $id
 * @property int $employee_id
 * @property Carbon $effective_from
 * @property int|null $mon_shift_id
 * @property int|null $tue_shift_id
 * @property int|null $wed_shift_id
 * @property int|null $thu_shift_id
 * @property int|null $fri_shift_id
 * @property int|null $sat_shift_id
 * @property int|null $sun_shift_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee|null $employee
 */
class ShiftPattern extends Model
{
    use ScopedRouteBinding;

    protected $table = 'employee_shift_patterns';

    /** Kunci hari form/payload (05 §4.9 `days{mon..sun}`) → kolom, urut ISO (Senin = 1). */
    public const DAYS = [
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
        7 => 'sun',
    ];

    protected $fillable = ['employee_id', 'effective_from', 'mon_shift_id', 'tue_shift_id', 'wed_shift_id', 'thu_shift_id', 'fri_shift_id', 'sat_shift_id', 'sun_shift_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'employee_id' => 'integer',
            'mon_shift_id' => 'integer',
            'tue_shift_id' => 'integer',
            'wed_shift_id' => 'integer',
            'thu_shift_id' => 'integer',
            'fri_shift_id' => 'integer',
            'sat_shift_id' => 'integer',
            'sun_shift_id' => 'integer',
        ];
    }

    public static function scopedEmployeeColumn(): string
    {
        return 'employee_id';
    }

    public static function column(string $day): string
    {
        return "{$day}_shift_id";
    }

    /** Shift pada tanggal itu menurut pola ini (null = libur pada hari itu). */
    public function shiftIdOn(CarbonInterface $date): ?int
    {
        $value = $this->getAttribute(self::column(self::DAYS[$date->dayOfWeekIso]));

        return $value === null ? null : (int) $value;
    }

    /**
     * Isi pola per hari untuk audit & tampilan: `['mon' => 3, ..., 'sun' => null]`.
     *
     * @return array<string, int|null>
     */
    public function days(): array
    {
        $days = [];
        foreach (self::DAYS as $day) {
            $value = $this->getAttribute(self::column($day));
            $days[$day] = $value === null ? null : (int) $value;
        }

        return $days;
    }

    /**
     * Shift yang dipakai sekumpulan pola dalam satu query, termasuk yang dinonaktifkan/dihapus
     * (soft delete) agar pola lama tetap terbaca.
     *
     * @param  iterable<ShiftPattern>  $patterns
     * @return Collection<int, Shift> id → shift
     */
    public static function shiftsOf(iterable $patterns): Collection
    {
        $ids = [];
        foreach ($patterns as $pattern) {
            foreach ($pattern->days() as $id) {
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids === [] ? new Collection : Shift::withTrashed()->whereIn('id', array_keys($ids))->get()->keyBy('id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
