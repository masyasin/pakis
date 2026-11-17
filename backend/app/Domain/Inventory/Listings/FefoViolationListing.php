<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockLocation;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Laporan pelanggaran FEFO (`admin.stock-audit.fefo.index`, katalog 07 §3.4: `stock_fefo_violations`,
 * BR-156, PA-64 b, izin `stock.audit_reports` PA-72): baris dokumen yang memakai batch selain kandidat
 * FEFO pertama (`fefo_override = true`). Paket A: baris mutasi; Paket B menambah baris penjualan.
 */
final class FefoViolationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_fefo_violations')
            ->searchPlaceholder('Cari nomor dokumen, SKU, atau batch')
            ->search(TextSearch::make()->prefix('stock_transfers.number')->prefix('products.sku')->prefix('stock_batches.batch_no'))
            ->filters([
                RelationFilter::make('stock_location_id', 'Lokasi asal')->source('stock_locations')->column('stock_transfers.from_stock_location_id')
                    ->validIds(fn () => StockLocation::query()),
                RelationFilter::make('product_id', 'Produk')->source('products')->column('stock_transfer_lines.product_id')
                    ->validIds(fn () => Product::query()),
                DateRangeFilter::make('created_at', 'Tanggal dokumen')->column('stock_transfers.created_at')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('created_at', 'Tanggal')->column('stock_transfers.created_at'),
                Sort::make('number', 'Nomor')->column('stock_transfers.number'),
            ])
            ->defaultSort('-created_at');
    }
}
