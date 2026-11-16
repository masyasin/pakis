<?php

namespace App\Domain\Catalog\Listings;

use App\Domain\Catalog\Enums\RegistrationStatus;
use App\Domain\Catalog\Support\RegistrationState;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Daftar izin edar (`admin.product-registrations.index`, katalog 07 §3.4 / 15 §7.3:
 * `product_registrations`). Master tanpa scope data; lihat = `products.view`.
 * Bawaan (15 §5): izin BERLAKU yang habis ≤ hari ini + `inventory.registration_reminder_days`,
 * paling dekat dulu — dua chip yang bisa dihapus untuk melihat semua.
 * Filter prinsipal ditambah T-072 saat tabel `principals` ada.
 */
final class ProductRegistrationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('product_registrations')
            ->searchPlaceholder('Cari nomor izin atau nama produk')
            ->search(TextSearch::make()
                ->prefix('registration_no')
                ->relation('product', 'name', prefix: false)
                ->relation('product', 'sku'))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->column('product_registrations.status')
                    ->options(RegistrationStatus::options())
                    ->default([RegistrationStatus::Active->value]),
                // Tanggal sipil (kolom DATE), bukan TIMESTAMP: tanpa onTimestamp().
                DateRangeFilter::make('valid_until', 'Berlaku sampai')
                    ->column('product_registrations.valid_until')
                    ->default(fn () => ['to' => RegistrationState::expiringUntil()]),
            ])
            ->sorts([
                Sort::make('valid_until', 'Berlaku sampai')->column('product_registrations.valid_until'),
                Sort::make('registration_no', 'Nomor izin')->column('product_registrations.registration_no'),
            ])
            ->defaultSort('valid_until');
    }
}
