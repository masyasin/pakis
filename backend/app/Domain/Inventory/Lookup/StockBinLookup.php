<?php

namespace App\Domain\Inventory\Lookup;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Models\StockBin;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `stock_bins` (05 §4.11, T-068): rak AKTIF satu lokasi stok (`parent` = ID lokasi stok,
 * wajib: rak tanpa lokasi tidak bermakna), dalam dimensi Stok pengguna (15 §6.2).
 */
final class StockBinLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('stock_locations.view') || $user->can('stock.view');
    }

    public function rules(): array
    {
        return ['parent' => ['required', 'regex:/^[1-9]\d{0,18}$/']];
    }

    public function query(array $params): Builder
    {
        return StockBin::query()->where('is_active', true)->where('stock_location_id', (int) $params['parent']);
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
        if (! $model instanceof StockBin) {
            throw new LogicException('Lookup menerima model selain StockBin.');
        }

        return ['value' => $model->id, 'label' => $model->code, 'hint' => $model->name ?? $model->zone->label()];
    }

    public function scope(Builder $query, User $user): void
    {
        app(DataScope::class)->forStock($query, $user, $query->qualifyColumn('stock_location_id'));
    }
}
