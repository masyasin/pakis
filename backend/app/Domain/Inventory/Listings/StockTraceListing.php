<?php

namespace App\Domain\Inventory\Listings;

use App\Domain\Catalog\Models\Product;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Telusur batch (`admin.stock.trace`, katalog 07 §3.4 / 15 §7.3: `stock_batches_trace`, UAT-A17
 * recall): semua batch produk itu dengan nomor batch yang dicari, di setiap lokasi dalam scope Stok
 * (Owner semua), termasuk yang saldonya 0/dimusnahkan agar jejak recall utuh. Produk + nomor batch
 * wajib diisi; tanpa keduanya controller menampilkan daftar kosong. Riwayat mutasi = kartu stok
 * dengan filter produk & pencarian nomor batch.
 */
final class StockTraceListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('stock_batches_trace')
            ->searchPlaceholder('Nomor batch (wajib)')
            ->search(TextSearch::make()->prefix('stock_batches.batch_no'))
            ->filters([
                RelationFilter::make('product_id', 'Produk (wajib)')->source('products')->column('stock_batches.product_id')
                    ->validIds(fn () => Product::query()),
            ])
            ->sorts([
                Sort::make('location', 'Lokasi')->column('stock_locations.code'),
                Sort::make('received_date', 'Tanggal terima')->column('stock_batches.received_date'),
            ])
            ->defaultSort('location');
    }
}
