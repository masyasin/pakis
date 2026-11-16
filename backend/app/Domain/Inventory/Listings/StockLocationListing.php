<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar lokasi stok (`admin.stock-locations.index`, katalog 07 §3.4 / 15 §7.3: `stock_locations`).
 * Dimensi Stok (15 §6.2) diterapkan controller lewat DataScope::forStock() sebelum ListingQuery.
 */
final class StockLocationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_locations')
            ->searchPlaceholder('Cari kode atau nama')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                MultiSelectFilter::make('type', 'Tipe')->column('type')->options(StockLocationType::options()),
                // Pilihan lokasi divalidasi dengan definisi lokasi yang sama dengan dimensi Stok.
                RelationFilter::make('location_id', 'Lokasi')
                    ->source('locations')
                    ->column('location_id')
                    ->validIds(fn () => self::scopedLocations()),
                BooleanFilter::make('is_active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('code', 'Kode'),
                Sort::make('name', 'Nama'),
                Sort::make('type', 'Tipe'),
            ])
            ->defaultSort('code');
    }

    /** @return Builder<Location> */
    private static function scopedLocations(): Builder
    {
        $user = request()->user();
        $query = Location::query();

        return $user instanceof User
            ? app(DataScope::class)->forStockByLocation($query, $user, 'locations.id')
            : $query->whereRaw('1 = 0');
    }
}
