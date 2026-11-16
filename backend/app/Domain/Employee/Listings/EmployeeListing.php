<?php

namespace App\Domain\Employee\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Employee\Models\Employee;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Position;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar pegawai (`admin.employees.index`, katalog 07 §3.4: `employees`). Ber-scope data (06 §5)
 * lewat `exportQuery()` yang juga dipakai controller, jadi daftar & ekspor tidak bisa berbeda.
 *
 * Lokasi/departemen/jabatan dibaca dari penugasan UTAMA yang berlaku hari ini. NIK tidak pernah
 * menjadi kolom pencarian (hanya hash yang tersimpan) dan di ekspor hanya termasking (SEC-010).
 */
final class EmployeeListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('employees')
            ->searchPlaceholder('Cari nama, nomor pegawai, HP, atau email')
            ->search(TextSearch::make()
                ->prefix('employee_number')
                ->contains('full_name')
                ->contains('email')
                // "0812 3456" harus menemukan `628123456…`: awalan 0/+62 diubah ke format simpan.
                ->using(function (Builder $q, string $word): void {
                    $q->orWhere('employees.phone', 'like', '%'.TextSearch::escapeLike(PhoneNormalizer::forSearch($word)).'%');
                }))
            ->filters([
                SelectFilter::make('status', 'Status')
                    ->options(['active' => 'Aktif', 'inactive' => 'Nonaktif', 'all' => 'Semua'])
                    ->default('active')
                    ->using(fn (Builder $q, string $value) => $value === 'all' ? $q : $q->where('employees.status', $value)),
                MultiSelectFilter::make('employment_status', 'Status kerja')
                    ->column('employment_status_id')
                    ->options(ReferenceItem::query()->forGroup(ReferenceGroup::EmploymentStatus)->orderBy('sort_order')
                        ->get(['id', 'name'])->mapWithKeys(fn (ReferenceItem $r) => [(string) $r->id => $r->name])->all()),
                RelationFilter::make('location', 'Lokasi')
                    ->source('locations')
                    ->validIds(fn () => self::visibleLocations())
                    ->using(fn (Builder $q, int $id) => $q->whereHas('primaryAssignment', fn (Builder $a) => $a->where('location_id', $id))),
                RelationFilter::make('department', 'Departemen')
                    ->source('departments')
                    ->validIds(fn () => Department::query())
                    ->using(fn (Builder $q, int $id) => $q->whereHas('primaryAssignment.position', fn (Builder $p) => $p->where('department_id', $id))),
                RelationFilter::make('position', 'Jabatan')
                    ->source('positions')
                    ->validIds(fn () => Position::query())
                    ->using(fn (Builder $q, int $id) => $q->whereHas('primaryAssignment', fn (Builder $a) => $a->where('position_id', $id))),
                BooleanFilter::make('has_account', 'Punya akun')
                    ->using(fn (Builder $q, bool $on) => $on ? $q->whereHas('user') : $q->whereDoesntHave('user')),
                DateRangeFilter::make('join_date', 'Tanggal masuk'),
            ])
            ->sorts([
                Sort::make('full_name', 'Nama'),
                Sort::make('employee_number', 'No. pegawai'),
                Sort::make('join_date', 'Tanggal masuk'),
            ])
            ->defaultSort('full_name')
            ->exportable();
    }

    /**
     * Pilihan filter lokasi mengikuti scope lokasi pengguna (lokasi lain → "tidak ditemukan").
     *
     * @return Builder<Location>
     */
    private static function visibleLocations(): Builder
    {
        $user = request()->user();

        return $user instanceof User
            ? app(LocationScope::class)->apply(Location::query(), $user)
            : Location::query()->whereRaw('1 = 0');
    }

    public function exportQuery(User $user): Builder
    {
        $query = Employee::query()->with([
            'employmentStatus:id,name',
            'primaryAssignment.position:id,name',
            'primaryAssignment.location:id,name',
            'user:id,employee_id,username',
        ]);

        return app(DataScope::class)->apply($query, $user, 'employees.id');
    }

    /**
     * @return ExportColumns<Employee>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Employee::class)
            ->add('No. pegawai', fn (Employee $e) => $e->employee_number)
            ->add('Nama', fn (Employee $e) => $e->full_name)
            // SEC-010: NIK hanya termasking; NIK lengkap tidak pernah ikut ekspor.
            ->add('NIK', fn (Employee $e) => $e->maskedNik())
            ->add('L/P', fn (Employee $e) => $e->gender->value)
            ->add('Jabatan', fn (Employee $e) => $e->primaryAssignment?->position?->name)
            ->add('Lokasi', fn (Employee $e) => $e->primaryAssignment?->location?->name)
            ->add('Status kerja', fn (Employee $e) => $e->employmentStatus?->name)
            ->add('HP', fn (Employee $e) => $e->phone)
            ->add('Email', fn (Employee $e) => $e->email)
            ->add('Tanggal masuk', fn (Employee $e) => $e->join_date->toDateString())
            ->add('Akun', fn (Employee $e) => $e->user?->username)
            ->add('Status', fn (Employee $e) => $e->status->label());
    }
}
