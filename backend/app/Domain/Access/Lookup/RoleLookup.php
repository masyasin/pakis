<?php

namespace App\Domain\Access\Lookup;

use App\Domain\Access\Models\Role;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `roles` (05 §4.11): pilihan role di filter & form Pengguna (T-020). Role tidak ber-scope
 * dan tidak punya status aktif; hanya pemegang `roles.view` atau `users.view`.
 */
final class RoleLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('roles.view') || $user->can('users.view');
    }

    public function query(array $params): Builder
    {
        return Role::query()->where('guard_name', 'web');
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->contains('label')->prefix('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('label');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Role) {
            throw new LogicException('Lookup menerima model selain Role.');
        }

        return ['value' => $model->id, 'label' => $model->label, 'hint' => $model->name];
    }
}
