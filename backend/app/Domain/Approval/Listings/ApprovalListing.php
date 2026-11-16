<?php

namespace App\Domain\Approval\Listings;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Support\ApprovableRegistry;
use App\Domain\Approval\Support\ApprovalVisibility;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;

/**
 * Kotak masuk persetujuan (`admin.approvals.index`, katalog 15 §7.3 / 07 §3.4 `approvals`).
 *
 * Filter `tab` (wajib, default `mine`) tidak menyentuh query di sini: controller membatasi query lewat
 * ApprovalVisibility::constrainTab SEBELUM ListingQuery, karena tabnya bergantung pengguna (calon
 * penyetuju dihitung mesin) dan tab `submitted`/`all` butuh `approvals.view` (PA-27). Kolom tanggal =
 * `created_at` (PA-30, bukan `requested_at`).
 */
final class ApprovalListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('approvals')
            ->searchPlaceholder('Cari nomor dokumen atau nama pengaju')
            ->search(TextSearch::make()
                ->contains('summary')
                ->relation('requester', 'name', prefix: false))
            ->filters([
                SelectFilter::make('tab', 'Tab')
                    ->options(ApprovalVisibility::tabLabels())
                    ->default(ApprovalVisibility::TAB_MINE)
                    ->required()
                    ->using(fn () => null),
                SelectFilter::make('document_type', 'Jenis dokumen')
                    ->options(array_map(fn (array $o) => ['value' => $o['value'], 'label' => $o['label']], ApprovableRegistry::options()))
                    ->column('approval_requests.document_type'),
                MultiSelectFilter::make('status', 'Status')
                    ->options(ApprovalStatus::options())
                    ->column('approval_requests.status'),
                DateRangeFilter::make('created_at', 'Diajukan')
                    ->column('approval_requests.created_at')
                    ->onTimestamp(),
            ])
            ->sorts([
                Sort::make('created_at', 'Diajukan')->column('approval_requests.created_at'),
                Sort::make('amount', 'Nominal')->column('approval_requests.amount'),
            ])
            ->defaultSort('-created_at');
    }
}
