<?php

namespace App\Domain\Attendance\Listings;

use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/** Daftar shift (katalog 07 §3.4: `shifts`). Tanpa ekspor. */
final class ShiftListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('shifts')
            ->searchPlaceholder('Cari kode atau nama shift')
            ->search(TextSearch::make()
                ->prefix('code')
                ->contains('name'))
            ->filters([
                BooleanFilter::make('is_active', 'Aktif'),
                BooleanFilter::make('crosses_midnight', 'Lintas hari'),
            ])
            ->sorts([
                Sort::make('start_time', 'Jam masuk'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('start_time');
    }
}
