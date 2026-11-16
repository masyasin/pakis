<?php

namespace App\Domain\Approval\Listings;

use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delegasi persetujuan (`admin.approval-delegations.index`, 07 §3.4 `approval_delegations`). Scope:
 * delegasi yang pengguna beri/terima, kecuali pemegang `approvals.configure` (semua) — diterapkan
 * controller. `active_on` = delegasi yang masa berlakunya beririsan dengan rentang (kolom
 * `start_date`/`end_date`, PA-30).
 */
final class ApprovalDelegationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('approval_delegations')
            ->searchPlaceholder('Cari nama pemberi atau penerima')
            ->search(TextSearch::make()
                ->relation('delegator', 'name', prefix: false)
                ->relation('delegate', 'name', prefix: false))
            ->filters([
                DateRangeFilter::make('active_on', 'Berlaku pada')
                    ->using(function (Builder $q, array $range): void {
                        if (isset($range['to'])) {
                            $q->where('approval_delegations.start_date', '<=', $range['to']);
                        }
                        if (isset($range['from'])) {
                            $q->where('approval_delegations.end_date', '>=', $range['from']);
                        }
                    }),
                BooleanFilter::make('is_active', 'Belum dicabut')->column('approval_delegations.is_active'),
            ])
            ->sorts([
                Sort::make('start_date', 'Mulai')->column('approval_delegations.start_date'),
                Sort::make('end_date', 'Selesai')->column('approval_delegations.end_date'),
            ])
            ->defaultSort('-start_date');
    }
}
