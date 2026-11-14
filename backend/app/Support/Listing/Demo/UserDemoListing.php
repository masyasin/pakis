<?php

namespace App\Support\Listing\Demo;

use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\Filters\NumberRangeFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listing contoh untuk halaman /dev/listing-demo (hanya environment local, T-005).
 * Memakai tabel `users` agar semua tipe filter bisa dicoba tanpa modul lain.
 */
final class UserDemoListing implements ExportableListing
{
    public function exportQuery(User $user): Builder
    {
        return User::query();
    }

    /**
     * @return ExportColumns<User>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(User::class)
            ->add('Nama', fn (User $u) => $u->name)
            ->add('Email', fn (User $u) => $u->email)
            ->add('Terverifikasi', fn (User $u) => $u->email_verified_at !== null ? 'Ya' : 'Tidak')
            ->add('MFA', fn (User $u) => $u->two_factor_confirmed_at !== null ? 'Aktif' : 'Belum aktif')
            ->add('Dibuat', fn (User $u) => $u->created_at);
    }

    public function spec(): ListingSpec
    {
        $domains = User::query()->selectRaw("SUBSTRING_INDEX(email, '@', -1) AS domain")
            ->distinct()->orderBy('domain')->limit(20)->pluck('domain')
            ->mapWithKeys(fn ($d) => [$d => $d])->all();

        return ListingSpec::make('dev_users_demo')
            ->searchPlaceholder('Cari nama atau email')
            ->search(TextSearch::make()->prefix('name')->contains('email'))
            ->filters([
                BooleanFilter::make('verified', 'Email terverifikasi')
                    ->default(true)
                    ->using(fn (Builder $q, bool $v) => $v ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at')),
                SelectFilter::make('domain', 'Domain email')->options($domains)
                    ->using(fn (Builder $q, string $v) => $q->where('email', 'like', '%@'.TextSearch::escapeLike($v))),
                MultiSelectFilter::make('mfa', 'MFA')->options([
                    'on' => 'Aktif',
                    'off' => 'Belum aktif',
                ])->using(fn (Builder $q, array $v) => count($v) === 1
                    ? ($v[0] === 'on' ? $q->whereNotNull('two_factor_confirmed_at') : $q->whereNull('two_factor_confirmed_at'))
                    : $q),
                DateRangeFilter::make('created_at', 'Dibuat')->onTimestamp(),
                NumberRangeFilter::make('id_range', 'Rentang ID')->column('id'),
                RelationFilter::make('user_id', 'Pengguna (ID)')->column('id')->source('users')
                    ->validIds(fn () => User::query()),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('email', 'Email'),
                Sort::make('created_at', 'Dibuat'),
            ])
            ->defaultSort('name')
            ->exportable();
    }
}
