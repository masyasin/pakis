<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Inventory\Enums\DisposalMethod;
use App\Domain\Inventory\Enums\DisposalReportStatus;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar berita acara (`admin.disposal-reports.index`, katalog 07 §3.4 / 15 §7.3: `disposal_reports`).
 * Scope Stok lokasi diterapkan controller lewat DataScope::forStock.
 */
final class DisposalReportListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('disposal_reports')
            ->searchPlaceholder('Cari nomor berita acara')
            ->search(TextSearch::make()->prefix('number'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('disposal_reports.status')->options(DisposalReportStatus::options()),
                MultiSelectFilter::make('method', 'Metode')->column('disposal_reports.method')->options(DisposalMethod::options()),
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')->column('disposal_reports.stock_location_id')
                    ->validIds(fn () => StockLocation::query()->whereIn('type', [StockLocationType::Warehouse->value, StockLocationType::Store->value])),
            ])
            ->sorts([
                Sort::make('created_at', 'Dibuat')->column('disposal_reports.created_at'),
                Sort::make('number', 'Nomor')->column('disposal_reports.number'),
                Sort::make('total_cost', 'Nilai')->column('disposal_reports.total_cost'),
            ])
            ->defaultSort('-created_at');
    }
}
