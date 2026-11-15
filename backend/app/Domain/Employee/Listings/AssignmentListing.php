<?php

namespace App\Domain\Employee\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Models\Assignment;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\LocationScope;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar penugasan (`admin.assignments.index`, katalog 07 §3.4 `assignments`). Ber-scope data lewat
 * `assignments.employee_id` (06 §5). "Aktif" = belum berakhir (termasuk mutasi terjadwal).
 */
final class AssignmentListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('assignments')
            ->searchPlaceholder('Cari nama atau nomor pegawai')
            ->search(TextSearch::make()
                ->relation('employee', 'full_name', prefix: false)
                ->relation('employee', 'employee_number'))
            ->filters([
                BooleanFilter::make('active', 'Aktif')
                    ->default(true)
                    ->using(function (Builder $q, bool $on): void {
                        $today = DisplayTime::toLocal(now())->toDateString();
                        $on
                            ? $q->where(fn (Builder $w) => $w->whereNull('assignments.end_date')->orWhere('assignments.end_date', '>=', $today))
                            : $q->where('assignments.end_date', '<', $today);
                    }),
                RelationFilter::make('location', 'Lokasi')
                    ->source('locations')
                    ->validIds(fn () => self::visibleLocations())
                    ->column('location_id'),
                RelationFilter::make('department', 'Departemen')
                    ->source('departments')
                    ->validIds(fn () => Department::query())
                    ->using(fn (Builder $q, int $id) => $q->whereHas('position', fn (Builder $p) => $p->where('department_id', $id))),
                RelationFilter::make('position', 'Jabatan')
                    ->source('positions')
                    ->validIds(fn () => Position::query())
                    ->column('position_id'),
                RelationFilter::make('supervisor', 'Atasan')
                    ->source('employees')
                    ->validIds(fn () => self::visibleEmployees())
                    ->column('supervisor_employee_id'),
                DateRangeFilter::make('start_date', 'Mulai'),
            ])
            ->sorts([
                Sort::make('start_date', 'Mulai'),
                Sort::make('employee_name', 'Nama pegawai')
                    ->using(fn (Builder $q, string $direction) => $q->orderBy(
                        Employee::withTrashed()->select('full_name')->whereColumn('employees.id', 'assignments.employee_id'),
                        $direction,
                    )),
            ])
            ->defaultSort('-start_date')
            ->exportable();
    }

    /** @return Builder<Location> */
    private static function visibleLocations(): Builder
    {
        $user = request()->user();

        return $user instanceof User
            ? app(LocationScope::class)->apply(Location::query(), $user)
            : Location::query()->whereRaw('1 = 0');
    }

    /** @return Builder<Employee> */
    private static function visibleEmployees(): Builder
    {
        $user = request()->user();

        return $user instanceof User
            ? app(DataScope::class)->apply(Employee::query(), $user, 'employees.id')
            : Employee::query()->whereRaw('1 = 0');
    }

    public function exportQuery(User $user): Builder
    {
        // Penugasan milik pegawai yang sudah dihapus (salah input, BR-055) tidak ikut tampil.
        $query = Assignment::query()
            ->whereHas('employee')
            ->with([
                'employee:id,full_name,employee_number',
                'position:id,name,department_id,attendance_mode',
                'position.department:id,name',
                'location:id,name',
                'supervisor:id,full_name',
            ]);

        return app(DataScope::class)->apply($query, $user, 'assignments.employee_id');
    }

    /**
     * @return ExportColumns<Assignment>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Assignment::class)
            ->add('No. pegawai', fn (Assignment $a) => $a->employee?->employee_number)
            ->add('Nama', fn (Assignment $a) => $a->employee?->full_name)
            ->add('Jabatan', fn (Assignment $a) => $a->position?->name)
            ->add('Departemen', fn (Assignment $a) => $a->position?->department?->name)
            ->add('Lokasi', fn (Assignment $a) => $a->location?->name)
            ->add('Atasan', fn (Assignment $a) => $a->supervisor?->full_name)
            ->add('Mode absen', fn (Assignment $a) => $a->effectiveAttendanceMode()?->label())
            ->add('Utama', fn (Assignment $a) => $a->is_primary ? 'Ya' : 'Tidak')
            ->add('Mulai', fn (Assignment $a) => $a->start_date->toDateString())
            ->add('Selesai', fn (Assignment $a) => $a->end_date?->toDateString());
    }
}
