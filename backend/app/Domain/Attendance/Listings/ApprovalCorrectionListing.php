<?php

namespace App\Domain\Attendance\Listings;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Koreksi yang menunggu keputusan pengguna di Pakis Pro (`api.v1.approvals.attendance-corrections.index`,
 * 07 §3.4 `approval_corrections`). Scope = CorrectionAuthority::constrainDecidable (06 §6, tanpa
 * milik sendiri), diterapkan controller. Terlama dulu: yang paling lama menunggu diputuskan duluan.
 */
final class ApprovalCorrectionListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('approval_corrections')
            ->searchPlaceholder('Cari nama/nomor pegawai atau nomor koreksi')
            ->search(TextSearch::make()
                ->prefix('number')
                ->relation('employee', 'employee_number')
                ->relation('employee', 'full_name', prefix: false))
            ->filters([
                MultiSelectFilter::make('status', 'Status')
                    ->options(CorrectionStatus::options())
                    ->default([CorrectionStatus::Pending->value]),
                DateRangeFilter::make('work_date', 'Tanggal kerja')->column('attendance_corrections.work_date'),
            ])
            ->sorts([
                Sort::make('created_at', 'Diajukan')->column('attendance_corrections.created_at'),
                Sort::make('work_date', 'Tanggal kerja')->column('attendance_corrections.work_date'),
            ])
            ->defaultSort('created_at');
    }
}
