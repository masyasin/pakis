<?php

namespace App\Domain\Inventory\Lookup;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Domain\Inventory\Models\StockLocation;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Lookup `stock_locations` (05 §4.11, T-068): pilihan lokasi stok di filter & form modul stok.
 * Hanya lokasi AKTIF dan dalam dimensi Stok pengguna (15 §6.2). `type` menyaring satu tipe,
 * `binnable=1` hanya gudang/toko (yang punya rak, 15 §4.8).
 *
 * Izin: `stock_locations.view` atau `stock.view`. Task stok berikutnya (T-077+) menambah izin
 * modulnya di sini bila perlu, bukan membuat lookup kedua.
 */
final class StockLocationLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('stock_locations.view') || $user->can('stock.view');
    }

    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(StockLocationType::class)],
            'binnable' => ['nullable', 'boolean'],
        ];
    }

    public function query(array $params): Builder
    {
        return StockLocation::query()->where('is_active', true)
            ->when(isset($params['type']), fn (Builder $q) => $q->where('type', (string) $params['type']))
            ->when(! empty($params['binnable']), fn (Builder $q) => $q->whereIn('type', array_map(fn (StockLocationType $t) => $t->value, StockLocationType::manual())));
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
        if (! $model instanceof StockLocation) {
            throw new LogicException('Lookup menerima model selain StockLocation.');
        }

        return ['value' => $model->id, 'label' => $model->name, 'hint' => $model->code];
    }

    /** Dimensi Stok (15 §6.2), bukan kolom pegawai. */
    public function scope(Builder $query, User $user): void
    {
        app(DataScope::class)->forStock($query, $user, $query->qualifyColumn('id'));
    }
}
