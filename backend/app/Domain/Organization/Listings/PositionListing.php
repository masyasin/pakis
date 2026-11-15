<?php

namespace App\Domain\Organization\Listings;

use App\Domain\Organization\Enums\AttendanceMode;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Position;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\NumberRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar jabatan (`admin.positions.index`, katalog 07 §3.4: `positions`). Tidak ber-scope.
 * Kolom jumlah pegawai menunggu T-025.
 */
final class PositionListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('positions')
            ->searchPlaceholder('Cari kode atau nama')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                RelationFilter::make('department', 'Departemen')
                    ->source('departments')
                    ->column('department_id')
                    ->validIds(fn () => Department::query()),
                MultiSelectFilter::make('mode', 'Mode absen')
                    ->column('attendance_mode')
                    ->options(collect(AttendanceMode::cases())->mapWithKeys(fn (AttendanceMode $m) => [$m->value => $m->label()])->all()),
                NumberRangeFilter::make('level', 'Level')->column('level'),
                BooleanFilter::make('active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('code', 'Kode'),
                Sort::make('level', 'Level'),
            ])
            ->defaultSort('name')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return Position::query()->with(['department:id,name', 'parent:id,name']);
    }

    /**
     * @return ExportColumns<Position>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Position::class)
            ->add('Kode', fn (Position $p) => $p->code)
            ->add('Nama', fn (Position $p) => $p->name)
            ->add('Departemen', fn (Position $p) => $p->department?->name)
            ->add('Atasan', fn (Position $p) => $p->parent?->name)
            ->add('Level', fn (Position $p) => $p->level)
            ->add('Mode absen', fn (Position $p) => $p->attendance_mode->label())
            ->add('Aktif', fn (Position $p) => $p->is_active ? 'Ya' : 'Tidak');
    }
}
