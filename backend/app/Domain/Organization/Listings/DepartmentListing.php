<?php

namespace App\Domain\Organization\Listings;

use App\Domain\Organization\Models\Department;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar departemen (`admin.departments.index`, katalog 07 §3.4: `departments`). Tidak ber-scope.
 * Kolom jumlah jabatan & jumlah pegawai menunggu T-023 / T-025.
 */
final class DepartmentListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('departments')
            ->searchPlaceholder('Cari kode atau nama')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                RelationFilter::make('parent', 'Induk')
                    ->source('departments')
                    ->column('parent_id')
                    ->validIds(fn () => Department::query()),
                BooleanFilter::make('active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('code', 'Kode'),
            ])
            ->defaultSort('name')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return Department::query()->with('parent:id,name');
    }

    /**
     * @return ExportColumns<Department>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Department::class)
            ->add('Kode', fn (Department $d) => $d->code)
            ->add('Nama', fn (Department $d) => $d->name)
            ->add('Induk', fn (Department $d) => $d->parent?->name)
            ->add('Aktif', fn (Department $d) => $d->is_active ? 'Ya' : 'Tidak');
    }
}
