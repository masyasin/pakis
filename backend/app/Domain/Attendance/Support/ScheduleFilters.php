<?php

namespace App\Domain\Attendance\Support;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Listing\Filters\RelationFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter bersama daftar jadwal (`schedules`, `schedule_overrides`, kalender). Lokasi/departemen/
 * jabatan mengikuti penugasan UTAMA hari ini, sama dengan daftar pegawai (EmployeeListing).
 * Pilihan lokasi & shift dibatasi scope pelaku (lokasi lain / shift lain → "tidak ditemukan").
 */
final class ScheduleFilters
{
    /**
     * @param  string  $via  prefiks relasi ke pegawai: '' untuk query Employee, 'employee.' untuk tabel anak
     * @return list<RelationFilter>
     */
    public static function organization(string $via = ''): array
    {
        $primary = "{$via}primaryAssignment";

        return [
            RelationFilter::make('location', 'Lokasi')
                ->source('locations')
                ->validIds(fn () => self::visibleLocations())
                ->using(fn (Builder $q, int $id) => $q->whereHas($primary, fn (Builder $a) => $a->where('location_id', $id))),
            RelationFilter::make('department', 'Departemen')
                ->source('departments')
                ->validIds(fn () => Department::query())
                ->using(fn (Builder $q, int $id) => $q->whereHas("{$primary}.position", fn (Builder $p) => $p->where('department_id', $id))),
            RelationFilter::make('position', 'Jabatan')
                ->source('positions')
                ->validIds(fn () => Position::query())
                ->using(fn (Builder $q, int $id) => $q->whereHas($primary, fn (Builder $a) => $a->where('position_id', $id))),
        ];
    }

    /** Filter shift (R); pemanggil mengisi `using()` sesuai tabelnya. */
    public static function shift(): RelationFilter
    {
        return RelationFilter::make('shift', 'Shift')
            ->source('shifts')
            ->validIds(fn () => self::visibleShifts());
    }

    /**
     * Pola yang memakai shift itu di salah satu hari.
     *
     * @param  Builder<*>  $pattern
     */
    public static function patternUses(Builder $pattern, int $shiftId): void
    {
        $pattern->where(function (Builder $w) use ($shiftId) {
            foreach (ShiftUsage::PATTERN_COLUMNS as $column) {
                $w->orWhere($w->qualifyColumn($column), $shiftId);
            }
        });
    }

    /** @return Builder<Location> */
    private static function visibleLocations(): Builder
    {
        $user = request()->user();

        // Tanpa pengguna (tidak terjadi di route ber-auth) = fail-closed.
        return $user instanceof User
            ? app(LocationScope::class)->apply(Location::query(), $user)
            : Location::query()->whereRaw('1 = 0');
    }

    /** @return Builder<Shift> */
    private static function visibleShifts(): Builder
    {
        $user = request()->user();

        return $user instanceof User
            ? app(ShiftScope::class)->apply(Shift::query(), $user)
            : Shift::query()->whereRaw('1 = 0');
    }
}
