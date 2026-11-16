<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Actions\AddBarcode;
use App\Domain\Catalog\Actions\RemoveBarcode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductBarcodeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Barcode produk (15 §5 `admin.products.barcodes.*`, FR-A09). Izin `can:products.manage` di rute. */
class ProductBarcodeController extends Controller
{
    public function store(ProductBarcodeRequest $request, Product $product, AddBarcode $action): RedirectResponse
    {
        $row = $action->handle($product, (int) $request->validated('product_unit_id'), (string) $request->validated('barcode'), $request->user()?->getAuthIdentifier());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Barcode {$row->barcode} ditambahkan."]);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'satuan']);
    }

    public function destroy(Request $request, Product $product, ProductBarcode $barcode, RemoveBarcode $action): RedirectResponse
    {
        abort_unless($barcode->product_id === $product->id, 404);
        $action->handle($product, $barcode);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Barcode {$barcode->barcode} dihapus."]);

        return to_route('admin.products.show', ['product' => $product, 'tab' => 'satuan']);
    }
}
