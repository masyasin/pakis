<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Laporan selisih opname per penghitung (`admin.stock-audit.counters.index`, katalog 07 §3.4:
 * `stock_opname_counters`, SEC-103 & TA-01, PA-67 a, izin `stock.audit_reports` PA-72). Baris =
 * opname × penghitung pertama: jumlah baris dihitung, baris yang hitungan pertamanya selisih dari
 * ekspektasi (snapshot + mutasi setelah freeze), total |selisih| qty, dan baris yang hitung ulangnya
 * berbeda dari hitungan pertama. Query dasar (agregat `agg`) dibangun controller.
 */
final class OpnameCounterListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_opname_counters')
            ->searchPlaceholder('Cari nomor opname')
            ->search(TextSearch::make()->prefix('stock_opnames.number'))
            ->filters([
                RelationFilter::make('stock_location_id', 'Lokasi')->source('stock_locations')->column('stock_opnames.stock_location_id')
                    ->validIds(fn () => StockLocation::query()),
                RelationFilter::make('counted_by', 'Penghitung')->source('users')->column('agg.counted_by')
                    ->validIds(fn () => User::query()),
                DateRangeFilter::make('frozen_at', 'Tanggal mulai hitung')->column('stock_opnames.frozen_at')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('frozen_at', 'Mulai hitung')->column('stock_opnames.frozen_at'),
                Sort::make('variance_lines', 'Baris selisih')->column('agg.variance_lines'),
            ])
            ->defaultSort('-frozen_at');
    }
}
