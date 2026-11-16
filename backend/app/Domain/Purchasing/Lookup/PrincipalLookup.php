<?php

namespace App\Domain\Purchasing\Lookup;

use App\Domain\Purchasing\Models\Principal;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `principals` (15 §5 "Lookup baru"): pilihan prinsipal di form produk/supplier/promo. Hanya
 * yang aktif. Bisa dilihat pemegang `products.view` juga, karena form produk memilih prinsipal.
 */
final class PrincipalLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('suppliers.view') || $user->can('products.view');
    }

    public function query(array $params): Builder
    {
        return Principal::query()->where('is_active', true);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Principal) {
            throw new LogicException('Lookup menerima model selain Principal.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->code];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
