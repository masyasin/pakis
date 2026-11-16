<?php

namespace App\Domain\Fleet\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Fleet\Enums\VehicleType;
use App\Domain\Organization\Models\Location;
use App\Models\User;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar kendaraan (`admin.vehicles.index`, katalog 07 §3.4 / 15 §7.3: `vehicles`). Scope lokasi
 * asal (`home_location_id`) memakai definisi dimensi Stok (15 §6.2), diterapkan controller.
 */
final class VehicleListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('vehicles')
            ->searchPlaceholder('Cari plat atau nama')
            // Plat disimpan tanpa spasi dan dicari per kata: "K 9021" tetap menemukan K9021AB.
            ->search(TextSearch::make()
                ->contains('plate_no')
                ->contains('name'))
            ->filters([
                MultiSelectFilter::make('vehicle_type', 'Jenis')->column('vehicle_type')->options(VehicleType::options()),
                BooleanFilter::make('is_canvas', 'Canvas')->column('is_canvas'),
                RelationFilter::make('home_location_id', 'Lokasi asal')
                    ->source('locations')
                    ->column('home_location_id')
                    ->validIds(function () {
                        $user = request()->user();

                        return $user instanceof User
                            ? app(DataScope::class)->forStockByLocation(Location::query(), $user, 'locations.id')
                            : Location::query()->whereRaw('1 = 0');
                    }),
                BooleanFilter::make('is_active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('plate_no', 'Plat'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('plate_no');
    }
}
