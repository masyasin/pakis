<?php

namespace App\Domain\Audit\Listings;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Time\DisplayTime;
use Illuminate\Database\Eloquent\Builder;

/** Daftar log keamanan (katalog 07 §3.4: `security_events`). Default 7 hari terakhir. */
final class SecurityEventListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('security_events')
            ->searchPlaceholder('Cari identifier, IP, atau nama pengguna')
            ->search(TextSearch::make()
                ->prefix('identifier')
                ->prefix('ip')
                ->relation('user', 'name'))
            ->filters([
                MultiSelectFilter::make('event', 'Event')->options(
                    collect(SecurityEventType::cases())->mapWithKeys(fn ($e) => [$e->value => $e->label()])->all(),
                ),
                MultiSelectFilter::make('channel', 'Kanal')->options(
                    collect(SecurityChannel::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all(),
                ),
                RelationFilter::make('user_id', 'Pengguna')->source('users')
                    ->validIds(fn () => User::query()),
                DateRangeFilter::make('occurred_at', 'Waktu')->onTimestamp()
                    ->default(fn () => [
                        'from' => DisplayTime::toLocal(now())->subDays(6)->toDateString(),
                        'to' => DisplayTime::toLocal(now())->toDateString(),
                    ]),
            ])
            ->sorts([
                Sort::make('occurred_at', 'Waktu'),
            ])
            ->defaultSort('-occurred_at')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return SecurityEvent::query()->with('user:id,name');
    }

    /**
     * @return ExportColumns<SecurityEvent>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(SecurityEvent::class)
            ->add('Waktu', fn (SecurityEvent $e) => $e->occurred_at)
            ->add('Event', fn (SecurityEvent $e) => $e->event->label())
            ->add('Pengguna', fn (SecurityEvent $e) => $e->user?->name)
            ->add('Identifier', fn (SecurityEvent $e) => $e->identifier)
            ->add('Kanal', fn (SecurityEvent $e) => $e->channel->label())
            ->add('IP', fn (SecurityEvent $e) => $e->ip)
            ->add('Konteks', fn (SecurityEvent $e) => $e->context);
    }
}
