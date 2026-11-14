<?php

namespace App\Domain\Access\Lookup;

use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `users`: untuk filter pelaku/pengguna di Audit & Log keamanan (07 §3.4) dan, nanti,
 * modul Pengguna. Hanya untuk pemegang `audit.view` atau `users.view` (keputusan 15 Nov 2026).
 *
 * Pengecualian BR-006 "hanya aktif": audit harus bisa menyaring pelaku yang akunnya sudah
 * dinonaktifkan, jadi akun nonaktif tetap muncul (kolom `is_active` datang di T-017).
 */
final class UserLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('audit.view') || $user->can('users.view');
    }

    public function query(array $params): Builder
    {
        return User::query();
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('name')->prefix('email');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('name')->orderBy('id');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof User) {
            throw new LogicException('Lookup menerima model selain User.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->email];
    }
}
