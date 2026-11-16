<?php

namespace App\Domain\Employee\Listings;

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportMappingProfile;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profil pemetaan impor (`admin.import-profiles.index`, katalog 07 §3.4: `import_profiles`).
 * Profil tidak memuat data pegawai, jadi tidak ber-scope; "terakhir dipakai" = `updated_at`.
 */
final class ImportProfileListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('import_profiles')
            ->searchPlaceholder('Cari nama profil')
            ->search(TextSearch::make()->contains('name'))
            ->filters([
                RelationFilter::make('created_by', 'Oleh')->source('users')
                    ->validIds(fn () => User::query()->whereIn('id', ImportMappingProfile::query()->whereNotNull('created_by')->select('created_by'))),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('updated_at', 'Terakhir dipakai'),
            ])
            ->defaultSort('name');
    }

    /** @return Builder<ImportMappingProfile> */
    public static function query(): Builder
    {
        return ImportMappingProfile::query()->with('creator:id,name')->where('type', ImportBatch::TYPE_EMPLOYEES);
    }
}
