<?php

namespace App\Domain\Catalog\Lookup;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Models\User;
use App\Support\Listing\Search\TextSearch;
use App\Support\Lookup\LookupSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lookup `products` (15 §5 "Lookup baru"): pilihan produk di dokumen (harga, PO, mutasi, opname).
 * Hanya produk AKTIF; barcode dicocokkan persis agar hasil scan langsung ketemu.
 */
final class ProductLookup extends LookupSource
{
    public function authorize(User $user): bool
    {
        return $user->can('products.view');
    }

    public function query(array $params): Builder
    {
        return Product::query()->with('baseUnit:id,name')->where('status', ProductStatus::Active->value);
    }

    public function search(): TextSearch
    {
        return TextSearch::make()
            ->prefix('sku')
            ->contains('name')
            ->using(function (Builder $query, string $word): void {
                $query->orWhereExists(fn ($q) => $q->selectRaw('1')->from('product_barcodes')
                    ->whereColumn('product_barcodes.product_id', 'products.id')
                    ->where('product_barcodes.barcode', $word));
            });
    }

    public function order(Builder $query): void
    {
        $query->orderBy('name');
    }

    public function option(Model $model): array
    {
        if (! $model instanceof Product) {
            throw new LogicException('Lookup menerima model selain Product.');
        }

        return [
            'value' => $model->id,
            'label' => "{$model->sku} · {$model->name}",
            'hint' => $model->package_size ?? $model->baseUnit->name,
        ];
    }

    /** Master tanpa scope data (06 §5). */
    public function scope(Builder $query, User $user): void {}
}
