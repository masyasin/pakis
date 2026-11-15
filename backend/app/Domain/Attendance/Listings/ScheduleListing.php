<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Models\ShiftPattern;
use App\Domain\Attendance\Support\ScheduleFilters;
use App\Domain\Employee\Models\Employee;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar pola jadwal (`admin.schedules.index`, katalog 07 §3.4 `schedules`): satu baris per
 * pegawai AKTIF dalam scope, dengan pola yang berlaku hari ini (`Employee::currentShiftPattern`).
 * Tanpa ekspor (07). Scope data diterapkan controller lewat `employees.id`.
 */
final class ScheduleListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('schedules')
            ->searchPlaceholder('Cari nama atau nomor pegawai')
            ->search(TextSearch::make()->prefix('employee_number')->contains('full_name'))
            ->filters([
                ...ScheduleFilters::organization(),
                ScheduleFilters::shift()
                    ->using(fn (Builder $q, int $id) => $q->whereHas('currentShiftPattern', fn (Builder $p) => ScheduleFilters::patternUses($p, $id))),
                BooleanFilter::make('unscheduled', 'Tanpa jadwal')
                    ->using(fn (Builder $q, bool $on) => $on ? $q->whereDoesntHave('currentShiftPattern') : $q->whereHas('currentShiftPattern')),
            ])
            ->sorts([
                Sort::make('employee_name', 'Nama pegawai')->column('full_name'),
                Sort::make('effective_from', 'Berlaku sejak')
                    ->using(fn (Builder $q, string $direction) => $q->orderBy(self::currentEffectiveFrom(), $direction)),
            ])
            ->defaultSort('employee_name');
    }

    /**
     * Subquery `effective_from` pola yang berlaku hari ini (NULL = tanpa jadwal) untuk sort.
     *
     * @return Builder<ShiftPattern>
     */
    private static function currentEffectiveFrom(): Builder
    {
        $today = DisplayTime::toLocal(now())->toDateString();

        return ShiftPattern::query()
            ->select('effective_from')
            ->whereColumn('employee_shift_patterns.employee_id', (new Employee)->qualifyColumn('id'))
            ->where('effective_from', '<=', $today)
            ->orderByDesc('effective_from')
            ->limit(1);
    }
}
