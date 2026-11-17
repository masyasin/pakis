<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stok minimum/maksimum (`admin.stock-levels.index`, katalog 07 §3.4 / 15 §7.3: `stock_levels`).
 * Baris = produk aktif; lokasi stok WAJIB (bawaan gudang/toko pertama dalam scope) dan filter itu
 * yang menggabungkan `product_stock_levels` + saldo lokasi, sehingga produk yang belum diberi batas
 * tetap muncul untuk diisi. Upsert lewat SaveStockLevel (T-085, audit seragam).
 */
final class StockLevelListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_levels')
            ->searchPlaceholder('Cari SKU atau nama produk')
            ->search(TextSearch::make()->prefix('products.sku')->contains('products.name'))
            ->filters([
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')
                    ->validIds(fn () => self::locations(Auth::user()))
                    ->default(fn () => self::locations(Auth::user())->orderBy('code')->value('id'))->required()
                    ->using(fn (Builder $q, mixed $ids) => self::joinLocation($q, (int) ((array) $ids)[0])),
                RelationFilter::make('category_id', 'Kategori')->source('product_categories')->column('products.product_category_id')
                    ->validIds(fn () => ProductCategory::query()),
                BooleanFilter::make('below_min', 'Di bawah minimum')
                    ->using(fn (Builder $q, mixed $v) => (bool) $v
                        ? $q->whereNotNull('lvl.min_qty_base')->whereRaw('COALESCE(bal.qty_on_hand, 0) < lvl.min_qty_base')
                        : $q->where(fn (Builder $w) => $w->whereNull('lvl.min_qty_base')->orWhereRaw('COALESCE(bal.qty_on_hand, 0) >= lvl.min_qty_base'))),
            ])
            ->sorts([
                Sort::make('product', 'Produk')->column('products.name'),
                Sort::make('sku', 'SKU')->column('products.sku'),
            ])
            ->defaultSort('product');
    }

    /** @return Builder<StockLocation> */
    public static function locations(mixed $user): Builder
    {
        $query = StockLocation::query()->where('is_active', true)
            ->whereIn('type', [StockLocationType::Warehouse->value, StockLocationType::Store->value]);

        return $user instanceof User ? app(DataScope::class)->forStock($query, $user, 'stock_locations.id') : $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<*>  $q
     * @return Builder<*>
     */
    private static function joinLocation(Builder $q, int $locationId): Builder
    {
        $balance = DB::table('stock_batches')->selectRaw('product_id, SUM(qty_on_hand) AS qty_on_hand')
            ->where('stock_location_id', $locationId)->groupBy('product_id');

        return $q->leftJoin('product_stock_levels as lvl', fn ($j) => $j->on('lvl.product_id', '=', 'products.id')->where('lvl.stock_location_id', '=', $locationId))
            ->leftJoinSub($balance, 'bal', 'bal.product_id', '=', 'products.id')
            ->addSelect(['lvl.min_qty_base', 'lvl.max_qty_base', 'bal.qty_on_hand'])
            ->selectRaw('? AS stock_location_id', [$locationId]);
    }
}
