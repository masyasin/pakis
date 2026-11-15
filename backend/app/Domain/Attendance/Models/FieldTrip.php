<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Enums\FieldTripRule;
use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tugas luar kota / dinas luar (04 §2.13, BR-071, T-036A). Selama `start_date`–`end_date`
 * (inklusif, tanggal lokal) tugas `approved` menambah lokasi absen yang sah bagi pegawainya.
 *
 * Terlihat bila SALAH SATU pegawainya dalam scope pelaku (06 §7.2a); selain itu route `{trip}` → 404.
 *
 * @property int $id
 * @property string $number
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property string $destination
 * @property string|null $purpose
 * @property FieldTripRule $attendance_rule
 * @property FieldTripStatus $status
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Employee> $employees
 * @property-read Collection<int, FieldTripDestination> $destinations
 */
class FieldTrip extends Model
{
    /** Kolom yang diaudit & dibandingkan saat ubah. `employee_ids` ditambahkan Action (pivot). */
    public const AUDITED = ['start_date', 'end_date', 'destination', 'purpose', 'attendance_rule'];

    /** Batas teknis titik tujuan per tugas (form & validasi). */
    public const MAX_DESTINATIONS = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'attendance_rule' => FieldTripRule::class,
            'status' => FieldTripStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Employee, $this> */
    public function employees(): BelongsToMany
    {
        // Termasuk pegawai yang kemudian dihapus (salah input): riwayat tugas tetap utuh.
        return $this->belongsToMany(Employee::class, 'field_trip_employees')->withTrashed();
    }

    /** @return HasMany<FieldTripDestination, $this> */
    public function destinations(): HasMany
    {
        return $this->hasMany(FieldTripDestination::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Tugas yang berlaku (approved) pada tanggal lokal itu.
     *
     * @param  Builder<FieldTrip>  $query
     */
    public function scopeApprovedOn(Builder $query, string $date): void
    {
        $query->where('status', FieldTripStatus::Approved->value)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date);
    }

    /**
     * Tugas yang punya minimal satu pegawai dalam scope pelaku (daftar & binding).
     *
     * @param  Builder<FieldTrip>  $query
     */
    public static function constrainToScope(Builder $query, User $user): void
    {
        $ids = app(DataScope::class)->visibleEmployeeIds($user);
        if ($ids === null) {
            return;
        }

        $query->whereExists(fn ($q) => $q->selectRaw('1')->from('field_trip_employees')
            ->whereColumn('field_trip_employees.field_trip_id', 'field_trips.id')
            ->whereIn('field_trip_employees.employee_id', $ids === [] ? [0] : $ids));
    }

    /** @return list<int> */
    public function employeeIds(): array
    {
        $ids = $this->employees()->pluck('employees.id')->map(fn ($id): int => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /**
     * Ringkasan untuk halaman (daftar & tab Tugas di detail pegawai). Butuh relasi `employees`.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'days' => $this->dayCount(),
            'destination' => $this->destination,
            'purpose' => $this->purpose,
            'rule' => ['value' => $this->attendance_rule->value, 'label' => $this->attendance_rule->label()],
            'status' => ['value' => $this->status->value, 'label' => $this->status->label()],
            'employees' => $this->employees->sortBy('full_name')
                ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'number' => $e->employee_number])
                ->values()->all(),
        ];
    }

    public function dayCount(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Nilai audit & perbandingan: tanggal `Y-m-d`, aturan sebagai string, pegawai terurut, titik
     * tujuan (alamat tempat tujuan, bukan jejak pegawai) dibulatkan 5 desimal.
     *
     * @param  list<int>  $employeeIds
     * @param  list<array{name: string, latitude: float, longitude: float, radius_m: int|null}>  $destinations
     * @return array<string, mixed>
     */
    public function auditValues(array $employeeIds, array $destinations): array
    {
        sort($employeeIds);

        return [
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'destination' => $this->destination,
            'purpose' => $this->purpose,
            'attendance_rule' => $this->attendance_rule->value,
            'destinations' => array_map(fn (array $d) => [
                'name' => $d['name'],
                'latitude' => round($d['latitude'], 5),
                'longitude' => round($d['longitude'], 5),
                'radius_m' => $d['radius_m'],
            ], $destinations),
            'employee_ids' => $employeeIds,
        ];
    }

    /** @return list<array{name: string, latitude: float, longitude: float, radius_m: int|null}> */
    public function destinationValues(): array
    {
        return array_values($this->destinations()->get()->map(fn (FieldTripDestination $d) => [
            'name' => $d->name,
            'latitude' => (float) $d->latitude,
            'longitude' => (float) $d->longitude,
            'radius_m' => $d->radius_m,
        ])->all());
    }

    /**
     * Ganti titik tujuan (aturan selain `destination` = kosong).
     *
     * @param  list<array{name: string, latitude: float, longitude: float, radius_m: int|null}>  $destinations
     */
    public function syncDestinations(array $destinations): void
    {
        $this->destinations()->delete();
        foreach ($destinations as $i => $d) {
            $this->destinations()->create([...$d, 'sort_order' => $i]);
        }
    }

    /**
     * Route binding ber-scope (SEC-033).
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

        $query = FieldTrip::query()->where($field ?? $this->getRouteKeyName(), $value);
        self::constrainToScope($query, $user);

        return $query->first();
    }
}
