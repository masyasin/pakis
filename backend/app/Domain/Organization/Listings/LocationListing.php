<?php

namespace App\Domain\Organization\Listings;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Support\LocationScope;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\Regency;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar lokasi (`admin.locations.index`, katalog 07 §3.4: `locations`). Scope "hanya lokasinya"
 * (06 §5) diterapkan controller/exportQuery lewat LocationScope sebelum ListingQuery.
 *
 * Kolom & sort "jumlah pegawai" menunggu tabel penugasan (T-025).
 */
final class LocationListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('locations')
            ->searchPlaceholder('Cari kode, nama, atau alamat')
            ->search(TextSearch::make()
                ->prefix('code')
                ->contains('name')
                ->contains('address'))
            ->filters([
                MultiSelectFilter::make('type', 'Tipe')
                    ->column('location_type_id')
                    ->options(ReferenceItem::query()->forGroup(ReferenceGroup::LocationType)
                        ->orderBy('sort_order')->pluck('name', 'id')->all()),
                BooleanFilter::make('active', 'Aktif')->column('is_active'),
                BooleanFilter::make('has_coordinates', 'Punya koordinat')
                    ->using(fn (Builder $q, bool $value) => $value
                        ? $q->whereNotNull($q->qualifyColumn('latitude'))
                        : $q->whereNull($q->qualifyColumn('latitude'))),
                // Kode desa hierarkis (`33.19.04.2001`): filter kab/kota = awalan kode, tanpa join
                // (pola RegionListing). Nilai sudah divalidasi regex & harus ada di tabel regencies.
                RelationFilter::make('regency', 'Kab/Kota')
                    ->source('regions')
                    ->params(['level' => 'regency'])
                    ->keyPattern('/^\d{2}\.\d{2}$/')
                    ->validIds(fn () => Regency::query())
                    ->using(fn (Builder $q, string $code) => $q->where($q->qualifyColumn('village_code'), 'like', TextSearch::escapeLike($code).'.%')),
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
        return app(LocationScope::class)->apply(
            Location::query()->with(['type:id,name', 'village.district.regency']),
            $user,
        );
    }

    /**
     * @return ExportColumns<Location>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Location::class)
            ->add('Kode', fn (Location $l) => $l->code)
            ->add('Nama', fn (Location $l) => $l->name)
            ->add('Tipe', fn (Location $l) => $l->type?->name)
            ->add('Alamat', fn (Location $l) => $l->address)
            ->add('Desa/Kelurahan', fn (Location $l) => $l->village?->name)
            ->add('Kecamatan', fn (Location $l) => $l->village?->district?->name)
            ->add('Kab/Kota', fn (Location $l) => $l->village?->district?->regency?->name)
            ->add('Latitude', fn (Location $l) => $l->latitude)
            ->add('Longitude', fn (Location $l) => $l->longitude)
            ->add('Radius presensi (m)', fn (Location $l) => $l->effectiveRadius())
            ->add('Telepon', fn (Location $l) => $l->phone)
            ->add('Aktif', fn (Location $l) => $l->is_active ? 'Ya' : 'Tidak');
    }
}
