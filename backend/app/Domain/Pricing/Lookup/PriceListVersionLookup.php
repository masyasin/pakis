<?php

namespace App\Domain\Pricing\Lookup;

use App\Domain\Pricing\Models\PriceListVersion;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Lookup `price_list_versions`: sumber filter `version` listing item (label "KODE · v3"). */
final class PriceListVersionLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('prices.view');
    }

    public function query(array $params): Builder
    {
        return PriceListVersion::query()->with('priceList:id,code,name');
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->relation('priceList', 'code')->relation('priceList', 'name', prefix: false);
    }

    public function order(Builder $query): void
    {
        $query->orderByDesc('id');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof PriceListVersion) {
            throw new LogicException('Lookup menerima model selain PriceListVersion.');
        }

        return ['value' => $model->id, 'label' => "{$model->priceList->code} · v{$model->version_no}", 'hint' => $model->status->label()];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
