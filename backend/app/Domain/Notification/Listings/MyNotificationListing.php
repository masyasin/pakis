<?php

namespace App\Domain\Notification\Listings;

use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Notifikasi milik sendiri di Pakis Pro (`api.v1.notifications.index`, 07 §3.4 `my_notifications`,
 * T-043). Isi sama dengan daftar web (`notifications`) tetapi filter sesuai katalog mobile: hanya
 * status baca. Scope "milik sendiri" diterapkan controller.
 */
final class MyNotificationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('my_notifications')
            ->searchPlaceholder('Cari judul atau isi')
            ->search(TextSearch::make()
                ->using(fn (Builder $q, string $word) => NotificationListing::matchJson($q, 'title', $word))
                ->using(fn (Builder $q, string $word) => NotificationListing::matchJson($q, 'body', $word)))
            ->filters([
                SelectFilter::make('read', 'Status baca')
                    ->options(['unread' => 'Belum dibaca', 'read' => 'Sudah dibaca'])
                    ->using(fn (Builder $q, mixed $value) => $value === 'unread' ? $q->whereNull('read_at') : $q->whereNotNull('read_at')),
            ])
            ->sorts([
                Sort::make('created_at', 'Waktu'),
            ])
            ->defaultSort('-created_at');
    }
}
