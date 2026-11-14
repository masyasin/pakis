<?php

namespace App\Domain\Access\Listings;

use App\Domain\Access\Enums\DataScope;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/** Daftar role (katalog 07 §3.4: `roles`). Tanpa ekspor. */
final class RoleListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('roles')
            ->searchPlaceholder('Cari nama, kode, atau deskripsi')
            ->search(TextSearch::make()
                ->contains('label')
                ->prefix('name')
                ->contains('description'))
            ->filters([
                MultiSelectFilter::make('data_scope', 'Scope')->options(
                    collect(DataScope::cases())->mapWithKeys(fn (DataScope $s) => [$s->value => $s->label()])->all(),
                ),
                BooleanFilter::make('mfa_required', 'Wajib MFA'),
                BooleanFilter::make('is_system', 'Role sistem'),
            ])
            ->sorts([
                Sort::make('sort_order', 'Urutan'),
                Sort::make('label', 'Nama'),
            ])
            ->defaultSort('sort_order');
    }
}
