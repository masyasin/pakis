<?php

namespace Tests\Fixtures\Listing;

use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\NumberRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\HasListing;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing contoh untuk test mesin listing. Mencakup semua tipe filter dan mode pencarian.
 */
class UserTestListing implements HasListing
{
    /** Nama yang dianggap di luar scope pengguna (mensimulasikan DataScope untuk filter relasi). */
    public const HIDDEN_NAME = 'Rahasia Scope';

    public function spec(): ListingSpec
    {
        return ListingSpec::make('test_users')
            ->searchPlaceholder('Cari nama, email, atau IP sesi')
            ->search(TextSearch::make()
                ->prefix('name')
                ->contains('email')
                ->relation('sessions', 'ip_address'))
            ->filters([
                SelectFilter::make('name', 'Nama')->options([
                    'Agus' => 'Agus',
                    'Budi' => 'Budi',
                ]),
                MultiSelectFilter::make('names', 'Beberapa nama')->column('name')->options([
                    ['value' => 'Agus', 'label' => 'Agus'],
                    ['value' => 'Budi', 'label' => 'Budi'],
                    ['value' => 'Citra', 'label' => 'Citra'],
                ]),
                BooleanFilter::make('verified', 'Email terverifikasi')
                    ->using(fn (Builder $q, bool $v) => $v ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at')),
                DateRangeFilter::make('created_at', 'Dibuat')->onTimestamp(),
                NumberRangeFilter::make('id_range', 'Rentang ID')->column('id'),
                RelationFilter::make('user_id', 'Pengguna')->column('id')->multiple()->source('users')
                    ->validIds(fn () => ListingTestUser::query()->where('name', '!=', self::HIDDEN_NAME)),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('email', 'Email'),
                Sort::make('created_at', 'Dibuat'),
            ])
            ->defaultSort('name');
    }
}
