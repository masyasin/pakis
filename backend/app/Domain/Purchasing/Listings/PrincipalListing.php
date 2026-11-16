<?php

namespace App\Domain\Purchasing\Listings;

use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar prinsipal (`admin.principals.index`, katalog 07 §3.4 / 15 §7.3: `principals`). Master tanpa
 * scope data; lihat = `suppliers.view`.
 */
final class PrincipalListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('principals')
            ->searchPlaceholder('Cari kode atau nama prinsipal')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                BooleanFilter::make('is_active', 'Aktif')->column('principals.is_active'),
            ])
            ->sorts([
                Sort::make('name', 'Nama')->column('principals.name'),
                Sort::make('code', 'Kode')->column('principals.code'),
            ])
            ->defaultSort('name');
    }
}
