<?php

namespace App\Domain\Catalog\Listings;

use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Support\RegistrationState;
use App\Models\User;
use App\Support\Export\ExportableListing;
use App\Support\Export\ExportColumns;
use App\Support\Listing\Filters\BooleanFilter;
use App\Support\Listing\Filters\RelationFilter;
use App\Support\Listing\Filters\SelectFilter;
use App\Support\Listing\ListingSpec;
use App\Support\Listing\Search\TextSearch;
use App\Support\Listing\Sort;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar produk (`admin.products.index`, katalog 07 §3.4 / 15 §7.3: `products`). Master tanpa
 * scope data (06 §5); hak lihat & ekspor = `products.view` (PA-3, pola Fase 0 untuk master).
 * Pencarian: awal SKU, nama/bahan aktif di mana saja, dan barcode PERSIS (hasil scan pemindai).
 * Filter prinsipal ditambah T-072 saat datanya ada.
 */
final class ProductListing implements ExportableListing
{
    public function spec(): ListingSpec
    {
        return ListingSpec::make('products')
            ->searchPlaceholder('Cari SKU, nama, bahan aktif, atau scan barcode')
            ->search(TextSearch::make()
                ->prefix('sku')
                ->contains('name')
                ->contains('active_ingredient')
                ->using(function (Builder $query, string $word): void {
                    $query->orWhereExists(fn ($q) => $q->selectRaw('1')->from('product_barcodes')
                        ->whereColumn('product_barcodes.product_id', 'products.id')
                        ->where('product_barcodes.barcode', $word));
                }))
            ->filters([
                RelationFilter::make('category', 'Kategori')
                    ->source('product_categories')
                    ->column('product_category_id')
                    ->validIds(fn () => ProductCategory::query()),
                SelectFilter::make('kind', 'Jenis')
                    ->options(CategoryKind::options())
                    ->using(fn (Builder $query, mixed $value) => $query->whereIn(
                        'products.product_category_id',
                        ProductCategory::query()->withTrashed()->where('kind', (string) $value)->select('id'),
                    )),
                BooleanFilter::make('restricted', 'Pestisida terbatas')->column('is_restricted'),
                SelectFilter::make('status', 'Status')->column('status')->options(ProductStatus::options()),
                // BR-152 (T-071): dihitung dari tanggal izin, bukan kolom status saja.
                SelectFilter::make('registration_status', 'Izin edar')
                    ->options(RegistrationState::options())
                    ->using(fn (Builder $query, mixed $value) => RegistrationState::apply($query, (string) $value)),
            ])
            ->sorts([
                Sort::make('name', 'Nama'),
                Sort::make('sku', 'SKU'),
            ])
            ->defaultSort('name')
            ->exportable();
    }

    public function exportQuery(User $user): Builder
    {
        return Product::query()->with(['category:id,name', 'baseUnit:id,name', 'formulation:id,name']);
    }

    /**
     * @return ExportColumns<Product>
     */
    public function exportColumns(): ExportColumns
    {
        return ExportColumns::for(Product::class)
            ->add('SKU', fn (Product $p) => $p->sku)
            ->add('Nama', fn (Product $p) => $p->name)
            ->add('Kategori', fn (Product $p) => $p->category->name)
            ->add('Merek', fn (Product $p) => $p->brand)
            ->add('Bahan aktif', fn (Product $p) => $p->active_ingredient)
            ->add('Formulasi', fn (Product $p) => $p->formulation?->name)
            ->add('Ukuran kemasan', fn (Product $p) => $p->package_size)
            ->add('Satuan dasar', fn (Product $p) => $p->baseUnit->name)
            ->add('Terbatas', fn (Product $p) => $p->is_restricted ? 'Ya' : 'Tidak')
            ->add('Bisa dijual', fn (Product $p) => $p->is_sellable ? 'Ya' : 'Tidak')
            ->add('Bisa dibeli', fn (Product $p) => $p->is_purchasable ? 'Ya' : 'Tidak')
            ->add('Status', fn (Product $p) => $p->status->label());
    }
}
