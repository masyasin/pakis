<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar mutasi (`admin.stock-transfers.index`, katalog 07 §3.4 / 15 §7.3: `stock_transfers`).
 * Scope Stok asal ATAU tujuan diterapkan controller (StockTransferPolicy::visible). Filter
 * `overdue` = masih `shipped` dan dikirim lebih dari `inventory.transfer_receive_reminder_hours`
 * jam lalu (sama dengan widget "Mutasi belum diterima" & pengingat, BR-161).
 */
final class StockTransferListing implements HasListing
{
    public function spec(): ListingSpec
    {
        $locations = fn () => StockLocation::query()->whereIn('type', [StockLocationType::Warehouse->value, StockLocationType::Store->value]);

        return ListingSpec::make('stock_transfers')
            ->searchPlaceholder('Cari nomor mutasi')
            ->search(TextSearch::make()->prefix('number'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('stock_transfers.status')->options(StockTransferStatus::options()),
                RelationFilter::make('from_stock_location_id', 'Dari')->source('stock_locations')->column('stock_transfers.from_stock_location_id')
                    ->validIds($locations),
                RelationFilter::make('to_stock_location_id', 'Ke')->source('stock_locations')->column('stock_transfers.to_stock_location_id')
                    ->validIds($locations),
                DateRangeFilter::make('shipped_at', 'Tanggal kirim')->column('stock_transfers.shipped_at')->onTimestamp(),
                BooleanFilter::make('overdue', 'Belum diterima lewat batas')
                    ->using(function (Builder $query, mixed $value): void {
                        $cutoff = now()->subHours((int) setting('inventory.transfer_receive_reminder_hours'));
                        if ((bool) $value) {
                            $query->where('stock_transfers.status', StockTransferStatus::Shipped->value)
                                ->where('stock_transfers.shipped_at', '<=', $cutoff);
                        } else {
                            $query->where(fn (Builder $q) => $q->where('stock_transfers.status', '<>', StockTransferStatus::Shipped->value)
                                ->orWhere('stock_transfers.shipped_at', '>', $cutoff));
                        }
                    }),
            ])
            ->sorts([
                Sort::make('created_at', 'Dibuat')->column('stock_transfers.created_at'),
                Sort::make('number', 'Nomor')->column('stock_transfers.number'),
                Sort::make('shipped_at', 'Tanggal kirim')->column('stock_transfers.shipped_at'),
            ])
            ->defaultSort('-created_at');
    }
}
