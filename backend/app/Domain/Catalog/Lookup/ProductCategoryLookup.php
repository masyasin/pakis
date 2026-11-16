<?php

namespace App\Domain\Catalog\Lookup;

use App\Domain\Catalog\Models\ProductCategory;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `product_categories` (15 §5 "Lookup baru"): pilihan induk kategori dan kategori di form
 * produk (T-070). Hanya kategori AKTIF; label memuat induk agar "Insektisida > Cair" jelas.
 */
final class ProductCategoryLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('products.view');
    }

    public function query(array $params): Builder
    {
        return ProductCategory::query()->with('parent:id,name')->where('is_active', true);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()->prefix('code')->contains('name');
    }

    public function order(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof ProductCategory) {
            throw new LogicException('Lookup menerima model selain ProductCategory.');
        }

        return [
            'value' => $model->id,
            'label' => $model->parent !== null ? "{$model->parent->name} › {$model->name}" : $model->name,
            'hint' => $model->code,
        ];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
