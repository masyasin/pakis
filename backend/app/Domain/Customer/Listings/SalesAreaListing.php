<?php

namespace App\Domain\Customer\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar area penjualan (`admin.sales-areas.index`, katalog 07 §3.4 / 15 §7.3: `sales_areas`).
 * Tanpa scope (area = master); pilihan filter supervisor tetap dibatasi pegawai dalam scope.
 */
final class SalesAreaListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('sales_areas')
            ->searchPlaceholder('Cari kode atau nama area')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                RelationFilter::make('parent_id', 'Induk')
                    ->source('sales_areas')
                    ->column('parent_id')
                    ->validIds(fn () => SalesArea::query()),
                RelationFilter::make('supervisor_employee_id', 'Supervisor')
                    ->source('sales_employees')
                    ->column('supervisor_employee_id')
                    ->validIds(fn () => self::scopedEmployees()),
                BooleanFilter::make('is_active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('code', 'Kode'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('code');
    }

    /** @return Builder<Employee> */
    public static function scopedEmployees(): Builder
    {
        $user = request()->user();
        $query = Employee::query();

        return $user instanceof User
            ? app(DataScope::class)->apply($query, $user, 'employees.id')
            : $query->whereRaw('1 = 0');
    }
}
