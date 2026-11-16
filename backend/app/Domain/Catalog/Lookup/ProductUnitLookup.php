<?php

namespace App\Domain\Catalog\Lookup;

use App\Domain\Catalog\Models\ProductUnit;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `product_units` (15 §5): satuan AKTIF satu produk (`parent` = ID produk) untuk baris
 * dokumen (harga, PO, mutasi). Label memuat konversi agar "Dus (= 24 Botol)" jelas.
 */
final class ProductUnitLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('products.view');
    }

    public function rules(): array
    {
        return ['parent' => ['required', 'regex:/^[1-9]\d{0,18}$/']];
    }

    public function query(array $params): Builder
    {
        return ProductUnit::query()->with(['unit:id,name', 'product.baseUnit:id,name'])
            ->where('is_active', true)
            ->where('product_id', (int) $params['parent']);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->relation('unit', 'name');
    }

    public function order(Builder $query): void
    {
        $query->orderByDesc('is_base')->orderBy('factor');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof ProductUnit) {
            throw new LogicException('Lookup menerima model selain ProductUnit.');
        }

        $base = $model->product->baseUnit->name;

        return [
            'value' => $model->id,
            'label' => $model->is_base ? $model->unit->name : "{$model->unit->name} (= {$model->factorLabel()} {$base})",
            'hint' => $model->is_default_sales ? 'Default jual' : null,
        ];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
