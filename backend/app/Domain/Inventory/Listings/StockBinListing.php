<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\BinZone;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
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
 * Daftar rak satu lokasi stok (`admin.stock-bins.index`, katalog 07 §3.4 / 15 §7.3: `stock_bins`).
 * `stock_location_id` wajib (15 §5): kosong/di luar scope jatuh ke gudang/toko aktif pertama dalam
 * scope pengguna, tidak pernah berarti "semua rak". Hanya gudang & toko yang berak (15 §4.8).
 */
final class StockBinListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_bins')
            ->searchPlaceholder('Cari kode rak')
            ->search(TextSearch::make()->prefix('code'))
            ->filters([
                RelationFilter::make('stock_location_id', 'Lokasi stok')
                    ->source('stock_locations')
                    ->params(['binnable' => '1'])
                    ->column('stock_location_id')
                    ->validIds(fn () => self::binnableLocations())
                    // Aktif lebih dulu; bila semua nonaktif tetap ada default, jadi "wajib" tidak pernah kosong
                    // selama pengguna melihat minimal satu gudang/toko (tanpa itu memang tidak ada rak terlihat).
                    ->default(fn () => self::binnableLocations()->orderByDesc('is_active')->orderBy('code')->value('id'))
                    ->required(),
                MultiSelectFilter::make('zone', 'Zona')->column('zone')->options(BinZone::options()),
                BooleanFilter::make('is_active', 'Aktif')->column('is_active'),
            ])
            ->sorts([
                Sort::make('code', 'Kode'),
                Sort::make('zone', 'Zona'),
            ])
            ->defaultSort('code');
    }

    /** @return Builder<StockLocation> lokasi stok gudang/toko dalam scope pengguna request */
    public static function binnableLocations(): Builder
    {
        $user = request()->user();
        $query = StockLocation::query()->whereIn('type', array_map(fn (StockLocationType $t) => $t->value, StockLocationType::manual()));

        return $user instanceof User
            ? app(DataScope::class)->forStock($query, $user, 'stock_locations.id')
            : $query->whereRaw('1 = 0');
    }
}
