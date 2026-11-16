<?php

namespace App\Domain\Purchasing\Listings;

use App\Domain\Purchasing\Models\Principal;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar supplier (`admin.suppliers.index`, katalog 07 §3.4 / 15 §7.3: `suppliers`). Master tanpa
 * scope data; lihat = `suppliers.view`. Rekening tidak pernah ikut daftar (SEC-108).
 */
final class SupplierListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('suppliers')
            ->searchPlaceholder('Cari kode, nama, atau NPWP')
            ->search(TextSearch::make()->prefix('code')->contains('name')->prefix('tax_id'))
            ->filters([
                RelationFilter::make('principal_id', 'Prinsipal')
                    ->source('principals')
                    ->column('suppliers.principal_id')
                    ->validIds(fn () => Principal::query()),
                BooleanFilter::make('is_active', 'Aktif')->column('suppliers.is_active'),
            ])
            ->sorts([
                Sort::make('name', 'Nama')->column('suppliers.name'),
                Sort::make('code', 'Kode')->column('suppliers.code'),
            ])
            ->defaultSort('name');
    }
}
