<?php

namespace App\Domain\Pricing\Lookup;

use App\Domain\Pricing\Enums\PriceListKind;
use App\Domain\Pricing\Models\PriceList;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Lookup `price_lists` (15 §5 "Lookup baru"): pilihan daftar harga di form kios (`customers.price_list_id`).
 * Hanya yang aktif. Bisa dilihat pemegang `customers.view` juga karena form kios memakainya.
 */
final class PriceListLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('prices.view') || $user->can('customers.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // `kind=tier` dipakai form & filter kios (PA-61): kios hanya bisa diberi daftar harga tier.
        return ['kind' => ['nullable', Rule::enum(PriceListKind::class)]];
    }

    public function query(array $params): Builder
    {
        return PriceList::query()->where('is_active', true)
            ->when(! empty($params['kind']), fn (Builder $q) => $q->where('kind', $params['kind']));
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('kind')->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof PriceList) {
            throw new LogicException('Lookup menerima model selain PriceList.');
        }

        return ['value' => $model->id, 'label' => "{$model->code} · {$model->name}", 'hint' => $model->kind->label()];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
