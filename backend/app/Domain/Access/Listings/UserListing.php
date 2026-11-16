<?php

namespace App\Domain\Access\Listings;

use App\Domain\Access\DataScope;
use App\Domain\Access\Models\Role;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\DateRangeFilter;
use App\Support\Listing\Filters\MultiSelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar pengguna (katalog 07 §3.4: `users`). Ber-scope data lewat pegawai terkait (06 §5,
 * keputusan Mas 15 Nov 2026). Ekspor tanpa kolom rahasia apa pun (password, MFA secret).
 */
final class UserListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('users')
            ->searchPlaceholder('Cari nama, username, email, atau HP')
            ->search(TextSearch::make()
                ->contains('name')
                ->prefix('username')
                ->prefix('email')
                // HP tersimpan `62…` (T-073): "0812…" dan "+62812…" diubah ke format simpan dulu.
                ->using(function (Builder $q, string $word): void {
                    $q->orWhere('users.phone', 'like', TextSearch::escapeLike(PhoneNormalizer::forSearch($word)).'%');
                }))
            ->filters([
                MultiSelectFilter::make('role', 'Role')
                    ->options(Role::query()->where('guard_name', 'web')->orderBy('sort_order')->pluck('label', 'name')->all())
                    ->using(fn (Builder $q, array $roles) => $q->whereHas('roles', fn (Builder $r) => $r->whereIn('name', $roles))),
                BooleanFilter::make('is_active', 'Aktif'),
                BooleanFilter::make('mfa', 'MFA aktif')
                    ->using(fn (Builder $q, bool $on) => $on ? $q->whereNotNull('two_factor_confirmed_at') : $q->whereNull('two_factor_confirmed_at')),
                BooleanFilter::make('has_employee', 'Punya pegawai')
                    ->using(fn (Builder $q, bool $on) => $on ? $q->whereNotNull('employee_id') : $q->whereNull('employee_id')),
                DateRangeFilter::make('last_login_at', 'Login terakhir')->onTimestamp(),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('username', 'Username'),
                Sort::make('last_login_at', 'Login terakhir'),
            ])
            ->defaultSort('name')
            ->exportable();
    }

    /**
     * Query dasar daftar & ekspor, sudah ber-scope untuk `$user` (job ekspor tidak punya sesi).
     *
     * @return Builder<User>
     */
    public function exportQuery(User $user): Builder
    {
        $query = User::query()->with(['roles:id,name,label', 'employee:id,full_name,employee_number']);

        return app(DataScope::class)->apply($query, $user, 'users.employee_id');
    }

    /**
     * @return ExportColumns<User>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(User::class)
            ->add('Nama', fn (User $u) => $u->name)
            ->add('Username', fn (User $u) => $u->username)
            ->add('Email', fn (User $u) => $u->email)
            ->add('HP', fn (User $u) => $u->phone)
            ->add('Role', fn (User $u) => $u->roles->pluck('label')->implode(', '))
            ->add('Aktif', fn (User $u) => $u->is_active ? 'Ya' : 'Tidak')
            ->add('MFA aktif', fn (User $u) => $u->two_factor_confirmed_at !== null ? 'Ya' : 'Tidak')
            ->add('Login terakhir', fn (User $u) => $u->last_login_at)
            ->add('No. pegawai', fn (User $u) => $u->employee?->employee_number)
            ->add('Pegawai', fn (User $u) => $u->employee?->full_name);
    }
}
