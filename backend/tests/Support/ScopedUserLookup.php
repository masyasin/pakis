<?php

namespace Tests\Support;

use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Sumber lookup uji ber-scope (T-019): baris `users` milik pegawai `users.employee_id`. */
final class ScopedUserLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function query(array $params): Builder
    {
        return User::query();
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('id');
    }

    public function option(Model $model): array
    {
        return ['value' => $model->getKey(), 'label' => (string) $model->getAttribute('name')];
    }

    public function scopeColumn(): ?string
    {
        return 'users.employee_id';
    }
}
