<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Models\ScheduleOverride;
use App\Domain\Attendance\Support\ScheduleFilters;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar pengecualian jadwal (`admin.schedule-overrides.index`, katalog 07 §3.4
 * `schedule_overrides`). Ber-scope data lewat `schedule_overrides.employee_id` (06 §5). Pegawai
 * yang dihapus (salah input, BR-055) tidak ikut tampil.
 */
final class ScheduleOverrideListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('schedule_overrides')
            ->searchPlaceholder('Cari nama/nomor pegawai atau catatan')
            ->search(TextSearch::make()
                ->relation('employee', 'full_name', prefix: false)
                ->relation('employee', 'employee_number')
                ->contains('note'))
            ->filters([
                // Default bulan ini (zona kantor); chip bisa dihapus untuk melihat semua tanggal.
                DateRangeFilter::make('date', 'Tanggal')->default(fn () => self::thisMonth()),
                ScheduleFilters::organization('employee.')[0],
                ScheduleFilters::shift()->column('shift_id'),
                BooleanFilter::make('day_off', 'Libur')
                    ->using(fn (Builder $q, bool $on) => $on ? $q->whereNull('schedule_overrides.shift_id') : $q->whereNotNull('schedule_overrides.shift_id')),
            ])
            ->sorts([
                Sort::make('date', 'Tanggal'),
                Sort::make('employee_name', 'Nama pegawai')
                    ->using(fn (Builder $q, string $direction) => $q->orderBy(
                        Employee::withTrashed()->select('full_name')->whereColumn('employees.id', 'schedule_overrides.employee_id'),
                        $direction,
                    )),
            ])
            ->defaultSort('-date')
            ->exportable();
    }

    /** @return array{from: string, to: string} */
    public static function thisMonth(): array
    {
        $now = DisplayTime::toLocal(now());

        return ['from' => $now->startOfMonth()->toDateString(), 'to' => $now->endOfMonth()->toDateString()];
    }

    /** @return Builder<ScheduleOverride> */
    public static function baseQuery(): Builder
    {
        return ScheduleOverride::query()
            ->whereHas('employee')
            ->with(['employee:id,full_name,employee_number', 'shift:id,code,name,color,is_active,deleted_at']);
    }

    public function exportQuery(User $user): Builder
    {
        $query = self::baseQuery()->with('employee.primaryAssignment.location:id,name');

        return app(DataScope::class)->apply($query, $user, 'schedule_overrides.employee_id');
    }

    /**
     * @return ExportColumns<ScheduleOverride>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(ScheduleOverride::class)
            ->add('Tanggal', fn (ScheduleOverride $o) => $o->date->toDateString())
            ->add('No. pegawai', fn (ScheduleOverride $o) => $o->employee?->employee_number)
            ->add('Nama', fn (ScheduleOverride $o) => $o->employee?->full_name)
            ->add('Lokasi', fn (ScheduleOverride $o) => $o->employee?->primaryAssignment?->location?->name)
            ->add('Jadwal', fn (ScheduleOverride $o) => $o->shift === null ? 'Libur' : "{$o->shift->code} · {$o->shift->name}")
            ->add('Catatan', fn (ScheduleOverride $o) => $o->note);
    }
}
