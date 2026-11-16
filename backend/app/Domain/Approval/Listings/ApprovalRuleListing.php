<?php

namespace App\Domain\Approval\Listings;

use App\Domain\Approval\Support\ApprovableRegistry;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aturan persetujuan (`admin.approval-rules.index`, 07 §3.4 `approval_rules`). Tanpa scope: hanya
 * pemegang `approvals.configure` (route). Default urut jenis dokumen lalu prioritas, yaitu urutan
 * RuleMatcher memeriksa aturan.
 */
final class ApprovalRuleListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('approval_rules')
            ->searchPlaceholder('Cari nama aturan')
            ->search(TextSearch::make()->contains('name'))
            ->filters([
                SelectFilter::make('document_type', 'Jenis dokumen')
                    ->options(array_map(fn (array $o) => ['value' => $o['value'], 'label' => $o['label']], ApprovableRegistry::options())),
                BooleanFilter::make('is_active', 'Aktif'),
            ])
            ->sorts([
                Sort::make('document_type', 'Jenis dokumen')->using(function (Builder $q, string $direction): void {
                    $q->orderBy('approval_rules.document_type', $direction)->orderBy('approval_rules.priority');
                }),
                Sort::make('priority', 'Prioritas'),
                Sort::make('name', 'Nama'),
            ])
            ->defaultSort('document_type');
    }
}
