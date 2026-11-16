<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Support\ProductFlags;
use App\Domain\Catalog\Support\ProductUsage;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\StoredUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ubah produk (FR-A08, BR-150, BR-151). SKU tidak bisa diubah (dipakai dokumen & barcode label).
 * Setelah ada mutasi stok: satuan dasar, `tracks_batch`, dan `tracks_serial` terkunci (stok sudah
 * tercatat per batch/seri dalam satuan itu). Melonggarkan penanda kategori butuh alasan.
 */
final class UpdateProduct
{
    /** Kolom yang terkunci setelah produk punya mutasi stok. */
    private const LOCKED_AFTER_MOVEMENT = ['base_unit_id', 'tracks_batch', 'tracks_serial'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecureUpload $uploads,
    ) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi ProductRequest */
    public function handle(Product $product, array $data, ?UploadedFile $photo, bool $removePhoto, ?int $userId): Product
    {
        $stored = $photo !== null ? $this->uploads->store($photo, UploadTarget::productPhoto()) : null;

        try {
            [$updated, $oldPhoto] = DB::transaction(fn () => $this->update($product, $data, $stored, $removePhoto, $userId));
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->uploads->delete($stored->disk, $stored->path);
            }

            throw $e;
        }

        // Berkas lama dihapus SETELAH commit: bila transaksi gagal, foto lama tetap utuh.
        if ($oldPhoto !== null) {
            $this->uploads->delete(Product::PHOTO_DISK, $oldPhoto);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Product, 1: string|null} produk & foto lama yang perlu dihapus
     */
    private function update(Product $product, array $data, ?StoredUpload $stored, bool $removePhoto, ?int $userId): array
    {
        $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
        $before = $locked->audited();

        $values = array_intersect_key($data, array_flip(Product::AUDITED));
        unset($values['sku']);
        foreach (array_keys(Product::FLAGS) as $flag) {
            if (array_key_exists($flag, $values)) {
                $values[$flag] = (bool) $values[$flag];
            }
        }

        if (ProductUsage::hasMovements($locked)) {
            foreach (self::LOCKED_AFTER_MOVEMENT as $column) {
                if (array_key_exists($column, $values) && $values[$column] != $locked->{$column}) {
                    throw ValidationException::withMessages([
                        $column => 'Produk ini sudah punya mutasi stok, jadi satuan dasar dan penanda batch/seri tidak bisa diubah.',
                    ]);
                }
            }
        }

        $category = ProductCategory::query()->withTrashed()->findOrFail((int) ($values['product_category_id'] ?? $locked->product_category_id));
        $flags = [];
        foreach (array_keys(Product::FLAGS) as $flag) {
            $flags[$flag] = (bool) ($values[$flag] ?? $locked->{$flag});
        }
        $relaxed = ProductFlags::relaxed($category, $flags);
        $newlyRelaxed = array_values(array_filter($relaxed, fn (string $f) => $locked->{$f} === true || $category->id !== $locked->product_category_id));
        ProductFlags::assertReason($newlyRelaxed, $data['flag_reason'] ?? null);

        if (array_key_exists('base_unit_id', $values) && (int) $values['base_unit_id'] !== $locked->base_unit_id) {
            $this->changeBaseUnit($locked, (int) $values['base_unit_id'], $userId);
        }

        $oldPhoto = null;
        if ($stored !== null || $removePhoto) {
            $oldPhoto = $locked->photo_path;
            $values['photo_path'] = $stored?->path;
        }

        $locked->forceFill([...$values, 'updated_by' => $userId]);
        if (! $locked->isDirty()) {
            return [$locked, null];
        }
        $locked->save();

        $after = $locked->audited();
        $this->audit->record('product.updated', $locked, $before, [...$after, 'photo' => $oldPhoto !== null || $stored !== null ? 'diganti' : null]);
        if ($newlyRelaxed !== []) {
            $this->audit->record('product.flags_relaxed', $locked, null, ['flags' => $newlyRelaxed, 'reason' => trim((string) $data['flag_reason'])]);
        }

        return [$locked, $oldPhoto];
    }

    /** Satuan dasar baru (belum ada mutasi): baris `is_base` ikut berganti satuan, faktor tetap 1. */
    private function changeBaseUnit(Product $product, int $unitId, ?int $userId): void
    {
        $exists = ProductUnit::query()->where('product_id', $product->id)->where('unit_id', $unitId)->where('is_base', false)->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'base_unit_id' => 'Satuan ini sudah terdaftar sebagai satuan lain produk ini. Hapus/nonaktifkan dulu atau pilih satuan lain.',
            ]);
        }

        ProductUnit::query()->where('product_id', $product->id)->where('is_base', true)
            ->update(['unit_id' => $unitId, 'updated_by' => $userId, 'updated_at' => now()]);
    }
}
