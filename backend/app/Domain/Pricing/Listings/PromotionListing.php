<?php

namespace App\Domain\Pricing\Listings;

use App\Domain\Pricing\Enums\PromotionFunding;
use App\Domain\Pricing\Enums\PromotionStatus;
use App\Domain\Pricing\Enums\PromotionType;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar promo (`admin.promotions.index`, katalog 07 §3.4 / 15 §7.3: `promotions`). Master tanpa
 * scope data; lihat = `promotions.view`. Filter `active_on` = promo yang periodenya bersinggungan
 * dengan rentang tanggal (lokal) itu.
 */
final class PromotionListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('promotions')
            ->searchPlaceholder('Cari kode atau nama promo')
            ->search(TextSearch::make()->prefix('code')->contains('name'))
            ->filters([
                SelectFilter::make('type', 'Tipe')->column('promotions.type')->options(PromotionType::options()),
                MultiSelectFilter::make('status', 'Status')->column('promotions.status')->options(PromotionStatus::options()),
                SelectFilter::make('funded_by', 'Sumber dana')->column('promotions.funded_by')->options(PromotionFunding::options()),
                DateRangeFilter::make('active_on', 'Berlaku pada')
                    ->using(function (Builder $q, array $range): void {
                        $tz = DisplayTime::timezone();
                        if (isset($range['to'])) {
                            $q->where('promotions.starts_at', '<', CarbonImmutable::parse($range['to'], $tz)->addDay()->utc());
                        }
                        if (isset($range['from'])) {
                            $q->where('promotions.ends_at', '>', CarbonImmutable::parse($range['from'], $tz)->utc());
                        }
                    }),
            ])
            ->sorts([
                Sort::make('starts_at', 'Mulai')->column('promotions.starts_at'),
                Sort::make('priority', 'Prioritas')->column('promotions.priority'),
            ])
            ->defaultSort('-starts_at');
    }
}
