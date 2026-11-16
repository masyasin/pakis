<?php

namespace App\Domain\Customer\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\CustomerVerification;
use App\Domain\Customer\Models\SalesArea;
use App\Models\User;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Antrean verifikasi kios (`admin.customer-verifications.index`, 15 §7.3 / 07 §3.4:
 * `customer_verifications`, T-074). Scope Pelanggan (15 §6.2) lewat `query()`; urut terlama dulu
 * agar pengajuan lama tidak tenggelam. Bawaan status = menunggu.
 *
 * Draf "menunggu telepon kantor" belum menjadi baris (PA-49), jadi tidak tampil di sini; ia tampil
 * di detail kios.
 */
final class CustomerVerificationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('customer_verifications')
            ->searchPlaceholder('Cari nama toko')
            ->search(TextSearch::make()->relation('customer', 'store_name', prefix: false))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('customer_verifications.status')
                    ->options(VerificationStatus::options())
                    ->default([VerificationStatus::Pending->value]),
                MultiSelectFilter::make('method', 'Bukti nomor')->column('customer_verifications.method')
                    ->options(VerificationMethod::options()),
                RelationFilter::make('sales_area_id', 'Area')
                    ->source('sales_areas')
                    ->validIds(fn () => SalesArea::query())
                    ->using(fn (Builder $q, mixed $id) => $q->whereHas('customer', fn (Builder $c) => $c->where('customers.sales_area_id', $id))),
            ])
            ->sorts([
                Sort::make('created_at', 'Diajukan')->column('customer_verifications.created_at'),
            ])
            ->defaultSort('created_at');
    }

    /** @return Builder<CustomerVerification> */
    public static function query(User $user): Builder
    {
        $query = CustomerVerification::query()
            ->select('customer_verifications.*')
            ->with(['customer:id,code,store_name,owner_name,sales_area_id,assigned_sales_employee_id,status', 'customer.salesArea:id,code,name', 'submitter:id,name']);

        return app(DataScope::class)->forCustomer($query, $user, 'customer_verifications.customer_id');
    }
}
