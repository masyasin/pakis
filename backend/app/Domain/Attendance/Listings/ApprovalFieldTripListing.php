<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Pengajuan tugas luar kota yang menunggu keputusan pengguna di Pakis Pro
 * (`api.v1.approvals.field-trips.index`, 07 §3.4 `approval_field_trips`, T-036A bagian 2). Scope =
 * FieldTripApprovals::constrainDecidable (06 §6, tanpa milik sendiri), diterapkan controller.
 * Urut tanggal mulai terdekat: perjalanan yang paling dekat diputuskan duluan.
 */
final class ApprovalFieldTripListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('approval_field_trips')
            ->searchPlaceholder('Cari nama/nomor pegawai atau nomor tugas')
            ->search(TextSearch::make()
                ->prefix('number')
                ->relation('employees', 'employee_number')
                ->relation('employees', 'full_name', prefix: false))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->options(FieldTripStatus::options())
                    ->default([FieldTripStatus::Pending->value]),
            ])
            ->sorts([
                Sort::make('start_date', 'Tanggal mulai')->column('field_trips.start_date'),
                Sort::make('created_at', 'Diajukan')->column('field_trips.created_at'),
            ])
            ->defaultSort('start_date');
    }
}
