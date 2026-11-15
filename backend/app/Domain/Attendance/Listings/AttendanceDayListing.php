<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\AttendanceStatus;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Support\AttendanceDayScope;
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
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Absensi harian (`admin.attendance.index`, katalog 07 §3.4 `attendance_days`, FR-039). Satu baris =
 * satu pegawai pada satu `work_date`. Ber-scope lewat AttendanceDayScope (06 §5: scope lokasi =
 * snapshot `location_id`). Filter tanggal default HARI INI (zona kantor) agar query selalu memakai
 * index `att_days_date_*`. Ekspor tidak memuat koordinat maupun selfie (SEC-013, SEC-051).
 */
final class AttendanceDayListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('attendance_days')
            ->searchPlaceholder('Cari nama atau nomor pegawai')
            ->search(TextSearch::make()
                ->relation('employee', 'full_name', prefix: false)
                ->relation('employee', 'employee_number'))
            ->filters([
                DateRangeFilter::make('work_date', 'Tanggal')
                    ->default(function () {
                        $today = DisplayTime::toLocal(now())->toDateString();

                        return ['from' => $today, 'to' => $today];
                    }),
                MultiSelectFilter::make('status', 'Status')
                    ->options(collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $s) => [$s->value => $s->label()])->all()),
                RelationFilter::make('location', 'Lokasi')
                    ->source('locations')
                    ->validIds(fn () => self::visibleLocations())
                    ->column('location_id'),
                RelationFilter::make('department', 'Departemen')
                    ->source('departments')
                    ->validIds(fn () => Department::query())
                    ->column('department_id'),
                RelationFilter::make('position', 'Jabatan')
                    ->source('positions')
                    ->validIds(fn () => Position::query())
                    ->column('position_id'),
                BooleanFilter::make('is_offline', 'Offline'),
                BooleanFilter::make('is_corrected', 'Dikoreksi'),
                BooleanFilter::make('has_anomaly', 'Anomali'),
            ])
            ->sorts([
                Sort::make('work_date', 'Tanggal'),
                Sort::make('employee_name', 'Nama pegawai')
                    ->using(fn (Builder $q, string $direction) => $q->orderBy(
                        Employee::withTrashed()->select('full_name')->whereColumn('employees.id', 'attendance_days.employee_id'),
                        $direction,
                    )),
                Sort::make('check_in_at', 'Jam masuk'),
                Sort::make('late_minutes', 'Telat'),
            ])
            ->defaultSort('-work_date')
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

    /** @return Builder<AttendanceDay> */
    public function exportQuery(User $user): Builder
    {
        $query = AttendanceDay::query()
            // Pegawai terhapus (salah input, BR-055) tidak ikut tampil.
            ->whereHas('employee')
            ->with([
                'employee:id,full_name,employee_number',
                'location:id,name',
                'position:id,name',
                'department:id,name',
                'shift:id,code,name,start_time,end_time',
            ]);

        return app(AttendanceDayScope::class)->apply($query, $user);
    }

    /**
     * @return ExportColumns<AttendanceDay>
     */
    public function exportColumns(): ExportColumns
    {
        $yes = fn (bool $v) => $v ? 'Ya' : 'Tidak';

        return ExportColumns::for(AttendanceDay::class)
            ->add('Tanggal', fn (AttendanceDay $d) => $d->work_date->toDateString())
            ->add('No. pegawai', fn (AttendanceDay $d) => $d->employee?->employee_number)
            ->add('Nama', fn (AttendanceDay $d) => $d->employee?->full_name)
            ->add('Lokasi', fn (AttendanceDay $d) => $d->location?->name)
            ->add('Departemen', fn (AttendanceDay $d) => $d->department?->name)
            ->add('Jabatan', fn (AttendanceDay $d) => $d->position?->name)
            ->add('Shift', fn (AttendanceDay $d) => $d->shift?->name)
            ->add('Masuk', fn (AttendanceDay $d) => self::clock($d->check_in_at))
            ->add('Pulang', fn (AttendanceDay $d) => self::clock($d->check_out_at))
            ->add('Telat (menit)', fn (AttendanceDay $d) => $d->late_minutes)
            ->add('Pulang cepat (menit)', fn (AttendanceDay $d) => $d->early_leave_minutes)
            ->add('Menit kerja', fn (AttendanceDay $d) => $d->work_minutes)
            ->add('Status', fn (AttendanceDay $d) => $d->status->label())
            ->add('Offline', fn (AttendanceDay $d) => $yes($d->is_offline))
            ->add('Dikoreksi', fn (AttendanceDay $d) => $yes($d->is_corrected))
            ->add('Anomali', fn (AttendanceDay $d) => $yes($d->has_anomaly));
    }

    /** Jam lokal `HH:MM`, dengan tanggal bila jatuh di hari lain (shift lintas tengah malam). */
    public static function clock(?CarbonInterface $at, ?string $workDate = null): ?string
    {
        if ($at === null) {
            return null;
        }
        $local = DisplayTime::toLocal($at);

        return $workDate !== null && $local->toDateString() !== $workDate ? $local->format('Y-m-d H:i') : $local->format('H:i');
    }
}
