<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Attendance\Models\AttendanceCorrection;
use App\Domain\Attendance\Support\CorrectionAuthority;
use App\Domain\Attendance\Support\ScheduleFilters;
use App\Domain\Employee\Models\Employee;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Koreksi presensi di web (`admin.attendance-corrections.index`, katalog 07 §3.4
 * `attendance_corrections`, FR-038, T-041). Ber-scope lewat CorrectionAuthority::constrainVisible
 * (scope data pegawai + koreksi yang diputuskan pengguna sebagai atasan langsung). Default status
 * `pending` agar daftar dibuka sebagai antrean kerja. Lokasi = penugasan utama pegawai hari ini,
 * sama dengan daftar pegawai. Ekspor tanpa path lampiran.
 */
final class AttendanceCorrectionListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('attendance_corrections')
            ->searchPlaceholder('Cari nomor koreksi atau nama/nomor pegawai')
            ->search(TextSearch::make()
                ->prefix('number')
                ->relation('employee', 'employee_number')
                ->relation('employee', 'full_name', prefix: false))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->options(CorrectionStatus::options())
                    ->default([CorrectionStatus::Pending->value]),
                DateRangeFilter::make('work_date', 'Tanggal kerja')->column('attendance_corrections.work_date'),
                MultiSelectFilter::make('reason', 'Alasan')
                    ->column('attendance_corrections.reason_id')
                    ->options(ReferenceItem::query()->forGroup(ReferenceGroup::CorrectionReason)->orderBy('sort_order')
                        ->get(['id', 'name'])->mapWithKeys(fn (ReferenceItem $r) => [(string) $r->id => $r->name])->all()),
                ScheduleFilters::organization('employee.')[0],
                RelationFilter::make('approver', 'Penyetuju')
                    ->source('employees')
                    ->validIds(fn () => self::visibleEmployees())
                    ->column('attendance_corrections.approver_employee_id'),
            ])
            ->sorts([
                Sort::make('created_at', 'Diajukan')->column('attendance_corrections.created_at'),
                Sort::make('work_date', 'Tanggal kerja')->column('attendance_corrections.work_date'),
            ])
            ->defaultSort('-created_at')
            ->exportable();
    }

    /** @return Builder<Employee> */
    private static function visibleEmployees(): Builder
    {
        $user = request()->user();
        if (! $user instanceof User) {
            return Employee::query()->whereRaw('1 = 0');
        }

        return app(DataScope::class)->apply(Employee::query(), $user, 'employees.id');
    }

    /** @return Builder<AttendanceCorrection> */
    public function exportQuery(User $user): Builder
    {
        $query = AttendanceCorrection::query()
            ->with(['employee:id,full_name,employee_number', 'reason:id,code,name', 'approver:id,full_name', 'submitter:id,name,employee_id', 'decider:id,name']);

        return app(CorrectionAuthority::class)->constrainVisible($query, $user);
    }

    /**
     * @return ExportColumns<AttendanceCorrection>
     */
    public function exportColumns(): ExportColumns
    {
        $time = fn ($at) => $at === null ? null : DisplayTime::toLocal($at)->format('Y-m-d H:i');

        return ExportColumns::for(AttendanceCorrection::class)
            ->add('Nomor', fn (AttendanceCorrection $c) => $c->number)
            ->add('Diajukan', fn (AttendanceCorrection $c) => $time($c->created_at))
            ->add('No. pegawai', fn (AttendanceCorrection $c) => $c->employee->employee_number)
            ->add('Nama', fn (AttendanceCorrection $c) => $c->employee->full_name)
            ->add('Tanggal kerja', fn (AttendanceCorrection $c) => $c->work_date->toDateString())
            ->add('Jenis', fn (AttendanceCorrection $c) => $c->type->label())
            ->add('Jam masuk usulan', fn (AttendanceCorrection $c) => AttendanceCorrection::hm($c->proposed_check_in))
            ->add('Jam pulang usulan', fn (AttendanceCorrection $c) => AttendanceCorrection::hm($c->proposed_check_out))
            ->add('Alasan', fn (AttendanceCorrection $c) => $c->reason->name)
            ->add('Keterangan', fn (AttendanceCorrection $c) => $c->note)
            ->add('Presensi manual', fn (AttendanceCorrection $c) => $c->isManual() ? 'Ya' : 'Tidak')
            ->add('Status', fn (AttendanceCorrection $c) => $c->status->label())
            ->add('Penyetuju', fn (AttendanceCorrection $c) => $c->approver?->full_name)
            ->add('Diputuskan oleh', fn (AttendanceCorrection $c) => $c->decider?->name)
            ->add('Diputuskan', fn (AttendanceCorrection $c) => $time($c->decided_at))
            ->add('Catatan keputusan', fn (AttendanceCorrection $c) => $c->decision_note);
    }
}
