<?php

namespace App\Domain\Pricing\Listings;

use App\Domain\Pricing\Enums\PriceListKind;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar harga (`admin.price-lists.index`, katalog 07 §3.4 / 15 §7.3: `price_lists`). Master tanpa
 * scope data; lihat = `prices.view`.
 */
final class PriceListListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('price_lists')
            ->searchPlaceholder('Cari kode atau nama daftar harga')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                SelectFilter::make('kind', 'Jenis')->column('price_lists.kind')->options(PriceListKind::options()),
                BooleanFilter::make('is_active', 'Aktif')->column('price_lists.is_active'),
            ])
            ->sorts([
                Sort::make('kind', 'Jenis')->column('price_lists.kind'),
                Sort::make('name', 'Nama')->column('price_lists.name'),
            ])
            ->defaultSort('kind');
    }
}
