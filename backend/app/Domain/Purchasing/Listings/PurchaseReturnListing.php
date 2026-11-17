<?php

namespace App\Domain\Purchasing\Listings;

use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\Supplier;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar retur ke prinsipal (`admin.purchase-returns.index`, katalog 07 §3.4 / 15 §7.3
 * `purchase_returns`). Scope Stok diterapkan controller lewat DataScope::forStock. Tanpa ekspor
 * (tidak ada di 15 §5).
 */
final class PurchaseReturnListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('purchase_returns')
            ->searchPlaceholder('Cari nomor retur')
            ->search(TextSearch::make()->prefix('number'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('purchase_returns.status')->options(PurchaseReturnStatus::options()),
                RelationFilter::make('supplier_id', 'Supplier')->source('suppliers')->column('purchase_returns.supplier_id')
                    ->validIds(fn () => Supplier::query()),
                RelationFilter::make('stock_location_id', 'Lokasi stok')->source('stock_locations')->column('purchase_returns.stock_location_id')
                    ->validIds(fn () => StockLocation::query()),
            ])
            ->sorts([
                Sort::make('created_at', 'Dibuat')->column('purchase_returns.created_at'),
                Sort::make('number', 'Nomor')->column('purchase_returns.number'),
                Sort::make('total_cost', 'Nilai')->column('purchase_returns.total_cost'),
            ])
            ->defaultSort('-created_at');
    }
}
