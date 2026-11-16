<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\CreateProductCategory;
use App\Domain\Catalog\Actions\DeleteProductCategory;
use App\Domain\Catalog\Actions\UpdateProductCategory;
use App\Domain\Catalog\Enums\CategoryKind;
use App\Domain\Catalog\Enums\StorageZone;
use App\Domain\Catalog\Listings\ProductCategoryListing;
use App\Domain\Catalog\Models\ProductCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductCategoryRequest;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kategori produk (15 §5 `admin.product-categories.*`, FR-A06, BR-151). Izin dicek middleware
 * rute `can:products.view` / `can:products.manage`; form di dialog halaman daftar.
 */
class ProductCategoryController extends Controller
{
    #[UsesListing(ProductCategoryListing::class)]
    public function index(Request $request): Response
    {
        $result = ListingQuery::apply(ProductCategory::query()->with('parent:id,name'), new ProductCategoryListing, $request)
            ->through(fn (ProductCategory $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'parent_id' => $c->parent_id,
                'parent_name' => $c->parent?->name,
                'kind' => $c->kind->value,
                'kind_label' => $c->kind->label(),
                'requires_batch' => $c->requires_batch,
                'requires_expiry' => $c->requires_expiry,
                'requires_serial' => $c->requires_serial,
                'requires_registration' => $c->requires_registration,
                'default_bin_zone' => $c->default_bin_zone->value,
                'sort_order' => $c->sort_order,
                'is_active' => $c->is_active,
            ]);

        return Inertia::render('product-categories/index', [
            ...$result->toInertia(),
            'kinds' => CategoryKind::options(),
            'binZones' => StorageZone::options(),
            // Pilihan induk: kategori aktif tingkat 1–2 saja (anak tingkat 3 tidak boleh punya anak).
            'parents' => fn () => ProductCategory::query()->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('parent_id')->orWhereIn('parent_id', ProductCategory::query()->whereNull('parent_id')->select('id')))
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (ProductCategory $c) => ['value' => $c->id, 'label' => $c->name, 'is_child' => $c->parent_id !== null]),
            'canManage' => $request->user()?->can('products.manage') ?? false,
        ]);
    }

    public function store(ProductCategoryRequest $request, CreateProductCategory $action): RedirectResponse
    {
        $category = $action->handle($request->validated(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori \"{$category->name}\" ditambahkan."]);

        return back();
    }

    public function update(ProductCategoryRequest $request, ProductCategory $category, UpdateProductCategory $action): RedirectResponse
    {
        $category = $action->handle($category, $request->validated(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori \"{$category->name}\" disimpan."]);

        return back();
    }

    public function destroy(Request $request, ProductCategory $category, DeleteProductCategory $action): RedirectResponse
    {
        $action->handle($category, $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Kategori \"{$category->name}\" dihapus."]);

        return back();
    }
}
