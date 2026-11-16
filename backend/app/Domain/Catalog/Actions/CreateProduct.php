<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Support\ProductFlags;
use App\Domain\Numbering\NumberGenerator;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\StoredUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tambah produk (FR-A08, BR-150, BR-151, T-070):
 * - SKU otomatis dari `NumberGenerator('product')` bila `product.sku_mode = auto` (di dalam
 *   transaksi), selain itu SKU ketikan (sudah divalidasi unik & format oleh request);
 * - penanda perilaku awal dari kategori; yang dilonggarkan butuh alasan dan dicatat audit;
 * - satuan dasar dibuat otomatis (faktor 1, default jual & beli);
 * - foto lewat SecureUpload (disk publik, diperkecil), dibuang lagi bila transaksi gagal.
 */
final class CreateProduct
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NumberGenerator $numbers,
        private readonly SecureUpload $uploads,
    ) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi ProductRequest */
    public function handle(array $data, ?UploadedFile $photo, ?int $userId): Product
    {
        $stored = $photo !== null ? $this->uploads->store($photo, UploadTarget::productPhoto()) : null;

        try {
            return DB::transaction(fn () => $this->create($data, $stored, $userId));
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->uploads->delete($stored->disk, $stored->path);
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function create(array $data, ?StoredUpload $stored, ?int $userId): Product
    {
        $category = ProductCategory::query()->sharedLock()->findOrFail((int) $data['product_category_id']);
        $flags = array_map(fn ($v) => (bool) $v, array_intersect_key($data, Product::FLAGS));
        $relaxed = ProductFlags::relaxed($category, $flags);
        ProductFlags::assertReason($relaxed, $data['flag_reason'] ?? null);

        $sku = setting('product.sku_mode') === 'auto'
            ? $this->numbers->next('product', isTaken: fn (string $n) => Product::withTrashed()->where('sku', $n)->exists())
            : (string) $data['sku'];

        $product = new Product;
        $product->forceFill([
            ...array_intersect_key($data, array_flip(Product::AUDITED)),
            ...$flags,
            'sku' => $sku,
            'status' => $data['status'] ?? ProductStatus::Active->value,
            'photo_path' => $stored?->path,
            'created_by' => $userId,
            'updated_by' => $userId,
        ])->save();

        $base = new ProductUnit;
        $base->forceFill([
            'product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'factor' => '1',
            'is_base' => true, 'is_default_sales' => true, 'is_default_purchase' => true, 'is_active' => true,
            'created_by' => $userId, 'updated_by' => $userId,
        ])->save();

        $this->audit->record('product.created', $product, null, [...$product->audited(), 'photo' => $stored !== null]);
        if ($relaxed !== []) {
            $this->audit->record('product.flags_relaxed', $product, null, ['flags' => $relaxed, 'reason' => trim((string) $data['flag_reason'])]);
        }

        return $product;
    }
}
