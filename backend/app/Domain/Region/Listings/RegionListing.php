<?php

namespace App\Domain\Region\Listings;

use App\Domain\Region\Models\District;
use App\Domain\Region\Models\Province;
use App\Domain\Region\Models\Regency;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar desa/kelurahan (katalog 07 §3.4: `regions`) dengan filter berjenjang.
 *
 * Kode wilayah hierarkis (`33.19.04.2001`), jadi filter provinsi/kab/kecamatan cukup memakai
 * awalan kode desa — memakai index PK, tanpa join. Nilai filter sudah divalidasi regex & harus ada
 * di tabelnya sebelum dipakai (RelationFilter), sehingga aman dipakai di LIKE.
 */
final class RegionListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('regions')
            ->searchPlaceholder('Cari nama desa atau kode')
            ->search(TextSearch::make()
                ->contains('name')
                ->prefix('code'))
            ->filters([
                self::level('province', 'Provinsi', '/^\d{2}$/', fn () => Province::query()),
                self::level('regency', 'Kab/Kota', '/^\d{2}\.\d{2}$/', fn () => Regency::query())->dependsOn('province'),
                self::level('district', 'Kecamatan', '/^\d{2}\.\d{2}\.\d{2}$/', fn () => District::query())->dependsOn('regency'),
            ])
            ->sorts([
                Sort::make('code', 'Kode'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('code');
    }

    /**
     * @param  \Closure(): Builder<*>  $validIds
     */
    private static function level(string $key, string $label, string $pattern, \Closure $validIds): RelationFilter
    {
        return RelationFilter::make($key, $label)
            ->source('regions')
            ->params(['level' => $key])
            ->keyPattern($pattern)
            ->validIds($validIds)
            ->using(fn (Builder $query, string $code) => $query->where($query->qualifyColumn('code'), 'like', TextSearch::escapeLike($code).'.%'));
    }
}
