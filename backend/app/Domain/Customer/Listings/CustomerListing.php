<?php

namespace App\Domain\Customer\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Domain\Region\Models\District;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Phone\PhoneNormalizer;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar kios (`admin.customers.index`, katalog 07 §3.4 / 15 §7.3: `customers`). Scope Pelanggan
 * (15 §6.2) lewat `exportQuery()` yang juga dipakai controller, jadi daftar & ekspor tidak bisa
 * berbeda. HP pemilik di ekspor SELALU termasking (SEC-108, keputusan Mas T-073).
 *
 * Sort default `store_name` (07 menyebut `name`; kolomnya bernama `store_name`, diselaraskan pola
 * PA-30). Filter `price_list_id` ditambah T-075 bersama tabel daftar harga.
 */
final class CustomerListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('customers')
            ->searchPlaceholder('Cari kode, nama toko, pemilik, atau HP')
            ->search(TextSearch::make()
                ->prefix('code')
                ->contains('store_name')
                ->contains('owner_name')
                // HP dicari PERSIS setelah normalisasi (`0812…` → `62812…`) agar index `customers_phone_idx` terpakai.
                ->using(function (Builder $q, string $word): void {
                    $phone = PhoneNormalizer::normalize($word);
                    if ($phone !== null) {
                        $q->orWhere('customers.owner_phone', $phone);
                    }
                }))
            ->filters([
                MultiSelectFilter::make('status', 'Status')->column('status')->options(CustomerStatus::options()),
                RelationFilter::make('sales_area_id', 'Area')
                    ->source('sales_areas')
                    ->column('sales_area_id')
                    ->validIds(fn () => SalesArea::query()),
                RelationFilter::make('assigned_sales_employee_id', 'Sales')
                    ->source('sales_employees')
                    ->column('assigned_sales_employee_id')
                    ->validIds(fn () => SalesAreaListing::scopedEmployees()),
                MultiSelectFilter::make('customer_type_id', 'Jenis')
                    ->column('customer_type_id')
                    ->options(ReferenceItem::query()->forGroup(ReferenceGroup::CustomerType)->orderBy('sort_order')
                        ->get(['id', 'name'])->mapWithKeys(fn (ReferenceItem $r) => [(string) $r->id => $r->name])->all()),
                BooleanFilter::make('verified', 'Terverifikasi')
                    ->using(fn (Builder $q, bool $on) => $on ? $q->whereNotNull('customers.verified_at') : $q->whereNull('customers.verified_at')),
                // Kode desa hierarkis (`33.19.04.2001`): kecamatan = awalan kode desa (pola LocationListing).
                RelationFilter::make('district_code', 'Kecamatan')
                    ->source('regions')
                    ->params(['level' => 'district'])
                    ->keyPattern('/^\d{2}\.\d{2}\.\d{2}$/')
                    ->validIds(fn () => District::query())
                    ->using(fn (Builder $q, string $code) => $q->where('customers.village_code', 'like', TextSearch::escapeLike($code).'.%')),
            ])
            ->sorts([
                Sort::make('store_name', 'Nama toko'),
                Sort::make('code', 'Kode'),
                Sort::make('created_at', 'Dibuat'),
            ])
            ->defaultSort('store_name')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        $query = Customer::query()->with([
            'customerType:id,name',
            'salesArea:id,code,name',
            'assignedSales:id,full_name',
            'village:code,name',
        ]);

        return app(DataScope::class)->forCustomer($query, $user, 'customers.id');
    }

    /**
     * @return ExportColumns<Customer>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Customer::class)
            ->add('Kode', fn (Customer $c) => $c->code)
            ->add('Nama toko', fn (Customer $c) => $c->store_name)
            ->add('Pemilik', fn (Customer $c) => $c->owner_name)
            // SEC-108: ekspor SELALU termasking, termasuk untuk pemegang customers.manage.
            ->add('HP pemilik', fn (Customer $c) => PhoneNormalizer::mask($c->owner_phone))
            ->add('Jenis', fn (Customer $c) => $c->customerType?->name)
            ->add('Area', fn (Customer $c) => $c->salesArea?->name)
            ->add('Sales', fn (Customer $c) => $c->assignedSales?->full_name)
            ->add('Desa', fn (Customer $c) => $c->village?->name)
            ->add('Alamat', fn (Customer $c) => $c->address)
            ->add('Termin (hari)', fn (Customer $c) => $c->term_days)
            ->add('Status', fn (Customer $c) => $c->status->label())
            ->add('Terverifikasi', fn (Customer $c) => $c->verified_at !== null ? 'Ya' : 'Belum')
            ->add('Dibuat', fn (Customer $c) => $c->created_at !== null ? DisplayTime::toLocal($c->created_at)->format('Y-m-d H:i') : null);
    }
}
