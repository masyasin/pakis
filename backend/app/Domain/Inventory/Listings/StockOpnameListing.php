<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Enums\StockOpnameScope;
use App\Domain\Inventory\Enums\StockOpnameStatus;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar opname (`admin.stock-opnames.index`, katalog 07 §3.4 / 15 §7.3: `stock_opnames`). Scope
 * Stok lokasi opname diterapkan controller lewat DataScope::forStock.
 */
final class StockOpnameListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_opnames')
            ->searchPlaceholder('Cari nomor opname')
            ->search(TextSearch::make()->prefix('number'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('stock_opnames.status')->options(StockOpnameStatus::options()),
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')->column('stock_opnames.stock_location_id')
                    ->validIds(fn () => StockLocation::query()->whereIn('type', [StockLocationType::Warehouse->value, StockLocationType::Store->value])),
                MultiSelectFilter::make('scope', 'Cakupan')->column('stock_opnames.scope')->options(StockOpnameScope::options()),
            ])
            ->sorts([
                Sort::make('created_at', 'Dibuat')->column('stock_opnames.created_at'),
                Sort::make('number', 'Nomor')->column('stock_opnames.number'),
            ])
            ->defaultSort('-created_at');
    }
}
