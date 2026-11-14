<?php

namespace App\Domain\Reference\Lookup;

use App\Domain\Reference\Enums\ReferenceGroup;
use App\Domain\Reference\Models\ReferenceItem;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Lookup `references?group=…`: pilihan referensi aktif satu grup (BR-026). Boleh untuk setiap
 * pengguna yang login, karena hanya label pilihan form (tidak sensitif, tidak ber-scope).
 */
final class ReferenceLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['group' => ['required', Rule::enum(ReferenceGroup::class)]];
    }

    public function query(array $params): Builder
    {
        return ReferenceItem::query()
            ->forGroup(ReferenceGroup::from((string) $params['group']))
            ->active();
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof ReferenceItem) {
            throw new LogicException('Lookup menerima model selain ReferenceItem.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->code];
    }
}
