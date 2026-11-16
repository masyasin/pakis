<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBarcode;
use App\Domain\Catalog\Models\ProductUnit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah barcode ke satuan produk (FR-A09). Unik GLOBAL: satu barcode hanya menunjuk satu produk &
 * satuan, sehingga pemindai di penerimaan/opname/kasir tidak pernah ragu. Duplikat yang lolos cek
 * karena balapan ditangkap index unik DB dan dijadikan pesan yang sama.
 */
final class AddBarcode
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Product $product, int $productUnitId, string $barcode, ?int $userId): ProductBarcode
    {
        $barcode = trim($barcode);

        return DB::transaction(function () use ($product, $productUnitId, $barcode) {
            $unit = ProductUnit::query()->where('product_id', $product->id)->find($productUnitId);
            if ($unit === null) {
                throw ValidationException::withMessages(['product_unit_id' => 'Satuan tidak ditemukan di produk ini.']);
            }

            $owner = ProductBarcode::query()->where('barcode', $barcode)->first();
            if ($owner !== null) {
                throw self::duplicate($owner);
            }

            $row = new ProductBarcode;
            try {
                $row->forceFill(['product_id' => $product->id, 'product_unit_id' => $unit->id, 'barcode' => $barcode])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['barcode' => 'Barcode ini sudah dipakai produk lain.']);
            }

            $this->audit->record('product_barcode.added', $product, null, ['barcode' => $barcode, 'unit' => $unit->unit_id]);

            return $row;
        });
    }

    private static function duplicate(ProductBarcode $owner): ValidationException
    {
        $sku = Product::withTrashed()->whereKey($owner->product_id)->value('sku');

        return ValidationException::withMessages(['barcode' => "Barcode ini sudah dipakai produk {$sku}."]);
    }
}
