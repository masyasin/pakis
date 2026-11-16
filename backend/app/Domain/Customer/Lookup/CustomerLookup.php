<?php

namespace App\Domain\Customer\Lookup;

use App\Domain\Access\DataScope;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `customers` (05 §4.11, T-073): kios dalam scope Pelanggan (15 §6.2), tanpa yang
 * `inactive`/`merged` (BR-006: hanya entri aktif). Dipakai dokumen jual (Paket B), promo, dll.
 */
final class CustomerLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('customers.view');
    }

    public function query(array $params): Builder
    {
        return Customer::query()->whereNotIn('status', [CustomerStatus::Inactive->value, CustomerStatus::Merged->value]);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('store_name')->contains('owner_name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('store_name')->orderBy('id');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Customer) {
            throw new LogicException('Lookup menerima model selain Customer.');
        }

        return ['value' => $model->id, 'label' => $model->store_name, 'hint' => "{$model->code} · {$model->owner_name}"];
    }

    /** Dimensi Pelanggan, bukan kolom pegawai. */
    public function scope(Builder $query, User $user): void
    {
        app(DataScope::class)->forCustomer($query, $user, $query->qualifyColumn('id'));
    }
}
