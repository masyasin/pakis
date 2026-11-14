<?php

namespace App\Support\Export;

use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar Unduhan milik sendiri (`admin.exports.index`, katalog 07 §3.4: `export_files`).
 * Scope "milik sendiri" diterapkan controller pada query sebelum ListingQuery.
 */
final class ExportFileListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('export_files')
            ->searchPlaceholder('Cari nama daftar')
            ->search(TextSearch::make()->contains('listing'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->options(
                    collect(ExportStatus::cases())->mapWithKeys(fn (ExportStatus $s) => [$s->value => $s->label()])->all(),
                ),
            ])
            ->sorts([
                Sort::make('created_at', 'Waktu'),
            ])
            ->defaultSort('-created_at');
    }
}
