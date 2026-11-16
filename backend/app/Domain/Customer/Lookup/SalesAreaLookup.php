<?php

namespace App\Domain\Customer\Lookup;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Models\SalesArea;
use App\Domain\Customer\Support\SalesAreaTree;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `sales_areas` (05 §4.11, T-073): area AKTIF untuk filter & form. Area = master tanpa scope.
 * - `exclude`    : sembunyikan satu area beserta sub-areanya (pilihan induk di dialog ubah area);
 * - `assignable` : hanya area yang boleh dipakai pelaku untuk kios baru/pengalihan
 *                  (DataScope::assignableSalesAreaIds: supervisor = area yang ia supervisi + sub-area).
 */
final class SalesAreaLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->canAny(['sales_areas.view', 'customers.view', 'customers.manage']);
    }

    public function rules(): array
    {
        return [
            'exclude' => ['nullable', 'integer', 'min:1'],
            'assignable' => ['nullable', 'boolean'],
        ];
    }

    public function query(array $params): Builder
    {
        $query = SalesArea::query()->with('parent:id,name')->where('is_active', true);

        if (! empty($params['exclude'])) {
            $query->whereNotIn('id', SalesAreaTree::withDescendants((int) $params['exclude']));
        }

        if (filter_var($params['assignable'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $user = request()->user();
            $ids = $user instanceof User ? app(DataScope::class)->assignableSalesAreaIds($user) : [];
            if ($ids !== null) {
                $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('id', $ids);
            }
        }

        return $query;
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('code');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof SalesArea) {
            throw new LogicException('Lookup menerima model selain SalesArea.');
        }

        $parent = $model->parent?->name;

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $parent !== null ? "{$model->code} · {$parent}" : $model->code];
    }
}
