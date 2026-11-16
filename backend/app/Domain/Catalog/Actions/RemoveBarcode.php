<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use Illuminate\Support\Facades\DB;

/** Hapus barcode produk (FR-A09). Barcode tidak dirujuk dokumen (dokumen menyimpan satuan), jadi aman dihapus. */
final class RemoveBarcode
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Product $product, ProductBarcode $barcode): void
    {
        DB::transaction(function () use ($product, $barcode) {
            $row = ProductBarcode::query()->where('product_id', $product->id)->lockForUpdate()->findOrFail($barcode->id);
            $row->delete();

            $this->audit->record('product_barcode.removed', $product, ['barcode' => $row->barcode], null);
        });
    }
}
