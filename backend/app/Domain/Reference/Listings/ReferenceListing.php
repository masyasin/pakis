<?php

namespace App\Domain\Reference\Listings;

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/** Daftar item referensi (katalog 07 §3.4: `references`). Grup wajib, default grup pertama. */
final class ReferenceListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('references')
            ->searchPlaceholder('Cari kode atau nama')
            ->search(TextSearch::make()
                ->prefix('code')
                ->contains('name'))
            ->filters([
                SelectFilter::make('group', 'Grup')
                    ->column('group_code')
                    ->options(collect(ReferenceGroup::cases())->mapWithKeys(fn ($g) => [$g->value => $g->label()])->all())
                    ->default(ReferenceGroup::cases()[0]->value)
                    ->required(),
                BooleanFilter::make('is_active', 'Aktif'),
                BooleanFilter::make('is_system', 'Item sistem'),
            ])
            ->sorts([
                Sort::make('sort_order', 'Urutan'),
                Sort::make('name', 'Nama'),
                Sort::make('code', 'Kode'),
            ])
            ->defaultSort('sort_order');
    }
}
