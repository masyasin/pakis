<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\SaveProductUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductUnitRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/** Satuan produk (15 §5 `admin.products.units.*`, BR-150). Izin `can:products.manage` di rute. */
class ProductUnitController extends Controller
{
    public function store(ProductUnitRequest $request, Product $product, SaveProductUnit $action): RedirectResponse
    {
        $action->handle($product, null, $request->validated(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Satuan ditambahkan.']);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'satuan']);
    }

    public function update(ProductUnitRequest $request, Product $product, ProductUnit $unit, SaveProductUnit $action): RedirectResponse
    {
        abort_unless($unit->product_id === $product->id, 404);
        $action->handle($product, $unit, $request->validated(), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Satuan disimpan.']);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'satuan']);
    }
}
