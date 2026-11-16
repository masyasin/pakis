<?php

namespace App\Domain\Notification\Listings;

use App\Domain\Notification\Enums\NotificationKind;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Notifikasi milik sendiri (`admin.notifications.index`, katalog 07 §3.4: `notifications`).
 * Scope "milik sendiri" diterapkan controller pada query sebelum ListingQuery.
 */
final class NotificationListing implements HasListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('notifications')
            ->searchPlaceholder('Cari judul atau isi')
            ->search(TextSearch::make()
                ->using(fn (Builder $q, string $word) => self::matchJson($q, 'title', $word))
                ->using(fn (Builder $q, string $word) => self::matchJson($q, 'body', $word)))
            ->filters([
                SelectFilter::make('read', 'Status baca')
                    ->options(['unread' => 'Belum dibaca', 'read' => 'Sudah dibaca'])
                    ->using(fn (Builder $q, mixed $value) => $value === 'unread' ? $q->whereNull('read_at') : $q->whereNotNull('read_at')),
                MultiSelectFilter::make('type', 'Jenis')->options(NotificationKind::options()),
                DateRangeFilter::make('created_at', 'Waktu')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('created_at', 'Waktu'),
            ])
            ->defaultSort('-created_at');
    }

    /**
     * Judul/isi ada di kolom JSON `data`. json_encode Laravel meng-escape huruf non-ASCII (é → é),
     * jadi pencarian dilakukan pada nilai hasil JSON_EXTRACT, bukan teks mentah kolom. Kata selalu
     * lewat binding + escapeLike; jalur JSON adalah konstanta (bukan input).
     *
     * @param  Builder<*>  $query
     * @param  'title'|'body'  $field
     */
    public static function matchJson(Builder $query, string $field, string $word): void
    {
        $sql = match ($field) {
            'title' => "LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.title'))) LIKE ?",
            'body' => "LOWER(JSON_UNQUOTE(JSON_EXTRACT(data, '$.body'))) LIKE ?",
        };

        $query->orWhereRaw($sql, ['%'.mb_strtolower(TextSearch::escapeLike($word)).'%']);
    }
}
