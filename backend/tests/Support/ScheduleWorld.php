<?php

namespace Tests\Support;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Location;
use Illuminate\Foundation\Testing\TestCase;

/**
 * Data uji modul jadwal (T-035) di atas EmployeeWorld: shift PAGI/SIANG, pembuat pola mingguan,
 * pengecualian, dan hari libur. Semua data fiktif (CLAUDE.md §6).
 */
final class ScheduleWorld
{
    public EmployeeWorld $org;

    public Shift $pagi;

    public Shift $siang;

    public static function make(TestCase $test): self
    {
        $world = new self;
        $world->org = EmployeeWorld::make($test);
        $world->pagi = self::shift(['code' => 'PAGI', 'name' => 'Pagi', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'color' => '#2F6B3B']);
        $world->siang = self::shift(['code' => 'SIANG', 'name' => 'Siang', 'start_time' => '12:00:00', 'end_time' => '20:00:00', 'color' => '#B7791F']);

        return $world;
    }

    /** @param  array<string, mixed>  $attributes */
    public static function shift(array $attributes = []): Shift
    {
        return Shift::query()->create([
            'code' => 'S'.fake()->unique()->numberBetween(100, 999),
            'name' => 'Shift Uji',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            ...$attributes,
        ]);
    }

    /**
     * Pola mingguan. `$days` = kunci `mon..sun` → shift (null = libur); kunci yang tidak disebut = libur.
     *
     * @param  array<string, Shift|null>  $days
     */
    public function pattern(Employee $employee, string $from, array $days): ShiftPattern
    {
        $row = ['employee_id' => $employee->id, 'effective_from' => $from];
        foreach (ShiftPattern::DAYS as $day) {
            $row[ShiftPattern::column($day)] = ($days[$day] ?? null)?->id;
        }

        return ShiftPattern::query()->create($row);
    }

    /** Senin–Sabtu satu shift, Minggu libur (pola demo 04 §4.2). */
    public function weekdays(Employee $employee, string $from, ?Shift $shift = null): ShiftPattern
    {
        $shift ??= $this->pagi;

        return $this->pattern($employee, $from, ['mon' => $shift, 'tue' => $shift, 'wed' => $shift, 'thu' => $shift, 'fri' => $shift, 'sat' => $shift]);
    }

    public function override(Employee $employee, string $date, ?Shift $shift, ?string $note = null): ScheduleOverride
    {
        return ScheduleOverride::query()->create(['employee_id' => $employee->id, 'date' => $date, 'shift_id' => $shift?->id, 'note' => $note]);
    }

    /** @param  list<Location>  $locations  kosong = berlaku semua lokasi */
    public function holiday(string $start, string $name, array $locations = [], ?string $end = null): Holiday
    {
        $holiday = Holiday::query()->create([
            'start_date' => $start,
            'end_date' => $end ?? $start,
            'name' => $name,
            'applies_to_all' => $locations === [],
        ]);
        $holiday->locations()->sync(array_map(fn (Location $l) => $l->id, $locations));

        return $holiday;
    }
}
