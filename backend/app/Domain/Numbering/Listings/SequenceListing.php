<?php

namespace App\Domain\Numbering\Listings;

use App\Domain\Numbering\Enums\ResetPeriod;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/** Daftar penomoran dokumen (katalog 07 §3.4: `sequences`). */
final class SequenceListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('sequences')
            ->searchPlaceholder('Cari kode atau nama')
            ->search(TextSearch::make()
                ->prefix('code')
                ->contains('name'))
            ->filters([
                MultiSelectFilter::make('reset_period', 'Periode reset')->options(
                    collect(ResetPeriod::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all(),
                ),
            ])
            ->sorts([
                Sort::make('code', 'Kode'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('code');
    }
}
