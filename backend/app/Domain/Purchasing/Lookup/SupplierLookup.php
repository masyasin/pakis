<?php

namespace App\Domain\Purchasing\Lookup;

use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `suppliers` (15 §5): pilihan supplier di PO/penerimaan. Supplier NONAKTIF tidak pernah
 * muncul (kriteria T-072), sehingga PO baru tidak bisa memesan ke supplier yang sudah dihentikan.
 */
final class SupplierLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('suppliers.view');
    }

    public function query(array $params): Builder
    {
        return Supplier::query()->where('is_active', true);
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
        if (! $model instanceof Supplier) {
            throw new LogicException('Lookup menerima model selain Supplier.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => "{$model->code} · tempo {$model->payment_term_days} hari"];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
