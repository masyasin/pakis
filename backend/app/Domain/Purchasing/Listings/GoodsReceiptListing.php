<?php

namespace App\Domain\Purchasing\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\GoodsReceiptStatus;
use App\Domain\Purchasing\Models\GoodsReceipt;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar penerimaan (`admin.goods-receipts.index`, katalog 07 §3.4 / 15 §7.3 `goods_receipts`).
 * Scope Stok diterapkan controller/`exportQuery`. Ekspor tanpa kolom biaya: harga beli mengikuti
 * SEC-107 (hanya di detail untuk pemegang izin), jadi ekspor daftar aman untuk `goods_receipts.view`.
 */
final class GoodsReceiptListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('goods_receipts')
            ->searchPlaceholder('Cari nomor penerimaan, nomor SJ, atau nomor PO')
            ->search(TextSearch::make()->prefix('number')->prefix('supplier_delivery_no')->relation('purchaseOrder', 'number'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('goods_receipts.status')->options(GoodsReceiptStatus::options()),
                RelationFilter::make('supplier_id', 'Supplier')->source('suppliers')->column('goods_receipts.supplier_id')
                    ->validIds(fn () => Supplier::query()),
                RelationFilter::make('stock_location_id', 'Lokasi stok')->source('stock_locations')->column('goods_receipts.stock_location_id')
                    ->validIds(fn () => StockLocation::query()),
                DateRangeFilter::make('received_at', 'Tanggal terima')->column('goods_receipts.received_at')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('received_at', 'Tanggal terima')->column('goods_receipts.received_at'),
                Sort::make('number', 'Nomor')->column('goods_receipts.number'),
            ])
            ->defaultSort('-received_at')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return app(DataScope::class)->forStock(
            GoodsReceipt::query()->with(['supplier:id,name', 'stockLocation:id,name', 'purchaseOrder:id,number']),
            $user,
            'goods_receipts.stock_location_id',
        );
    }

    /** @return ExportColumns<GoodsReceipt> */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(GoodsReceipt::class)
            ->add('Nomor', fn (GoodsReceipt $r) => $r->number)
            ->add('Tanggal terima', fn (GoodsReceipt $r) => DisplayTime::toLocal($r->received_at)->format('Y-m-d H:i'))
            ->add('Supplier', fn (GoodsReceipt $r) => $r->supplier->name)
            ->add('Lokasi stok', fn (GoodsReceipt $r) => $r->stockLocation->name)
            ->add('Nomor PO', fn (GoodsReceipt $r) => $r->purchaseOrder?->number)
            ->add('Nomor SJ supplier', fn (GoodsReceipt $r) => $r->supplier_delivery_no)
            ->add('Plat kendaraan', fn (GoodsReceipt $r) => $r->vehicle_plate)
            ->add('Status', fn (GoodsReceipt $r) => $r->status->label());
    }
}
