<?php

namespace App\Domain\Purchasing\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar PO (`admin.purchase-orders.index`, katalog 07 §3.4 / 15 §7.3: `purchase_orders`). Scope
 * Stok (gudang tujuan) diterapkan controller/`exportQuery` lewat DataScope::forStock. Filter
 * `overdue` = PO yang masih menunggu barang (`approved`/`partially_received`) dan `expected_date`
 * sebelum hari ini (tanggal lokal).
 */
final class PurchaseOrderListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('purchase_orders')
            ->searchPlaceholder('Cari nomor PO atau nama supplier')
            ->search(TextSearch::make()->prefix('number')->relation('supplier', 'name', prefix: false))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('purchase_orders.status')->options(PurchaseOrderStatus::options()),
                RelationFilter::make('supplier_id', 'Supplier')->source('suppliers')->column('purchase_orders.supplier_id')
                    ->validIds(fn () => Supplier::query()),
                RelationFilter::make('stock_location_id', 'Gudang tujuan')->source('stock_locations')->column('purchase_orders.stock_location_id')
                    ->validIds(fn () => StockLocation::query()),
                DateRangeFilter::make('order_date', 'Tanggal PO')->column('purchase_orders.order_date'),
                BooleanFilter::make('overdue', 'Terlambat datang')
                    ->using(function (Builder $query, mixed $value): void {
                        $today = CarbonImmutable::now(DisplayTime::timezone())->toDateString();
                        $open = [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::PartiallyReceived->value];
                        if ((bool) $value) {
                            $query->whereIn('purchase_orders.status', $open)->whereNotNull('purchase_orders.expected_date')
                                ->where('purchase_orders.expected_date', '<', $today);
                        } else {
                            $query->where(fn (Builder $q) => $q->whereNotIn('purchase_orders.status', $open)
                                ->orWhereNull('purchase_orders.expected_date')->orWhere('purchase_orders.expected_date', '>=', $today));
                        }
                    }),
            ])
            ->sorts([
                Sort::make('order_date', 'Tanggal PO')->column('purchase_orders.order_date'),
                Sort::make('number', 'Nomor')->column('purchase_orders.number'),
                Sort::make('grand_total', 'Total')->column('purchase_orders.grand_total'),
            ])
            ->defaultSort('-order_date')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return app(DataScope::class)->forStock(
            PurchaseOrder::query()->with(['supplier:id,name', 'stockLocation:id,name']),
            $user,
            'purchase_orders.stock_location_id',
        );
    }

    /**
     * @return ExportColumns<PurchaseOrder>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(PurchaseOrder::class)
            ->add('Nomor', fn (PurchaseOrder $o) => $o->number)
            ->add('Tanggal', fn (PurchaseOrder $o) => $o->order_date->toDateString())
            ->add('Supplier', fn (PurchaseOrder $o) => $o->supplier->name)
            ->add('Gudang tujuan', fn (PurchaseOrder $o) => $o->stockLocation->name)
            ->add('Perkiraan datang', fn (PurchaseOrder $o) => $o->expected_date?->toDateString())
            ->add('Tempo (hari)', fn (PurchaseOrder $o) => $o->payment_term_days)
            ->add('Subtotal', fn (PurchaseOrder $o) => $o->subtotal)
            ->add('Diskon', fn (PurchaseOrder $o) => $o->discount_total)
            ->add('Total', fn (PurchaseOrder $o) => $o->grand_total)
            ->add('Status', fn (PurchaseOrder $o) => $o->status->label());
    }
}
