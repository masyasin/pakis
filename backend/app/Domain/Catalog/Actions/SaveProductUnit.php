<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductUnit;
use App\Domain\Catalog\Support\ProductUsage;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah/ubah satuan produk (BR-150, FR-A07):
 * - faktor > 0, maks 3 desimal, "1 satuan ini = faktor satuan dasar"; satuan dasar tetap 1;
 * - faktor yang sudah dipakai dokumen tidak bisa diubah → `409 UNIT_FACTOR_LOCKED` (buat satuan
 *   baru dan nonaktifkan yang lama);
 * - maksimal satu default jual & satu default beli: menandai default baru mencabut yang lama dalam
 *   transaksi yang sama (produk dikunci agar dua permintaan bersamaan tidak sama-sama lolos).
 */
final class SaveProductUnit
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi ProductUnitRequest */
    public function handle(Product $product, ?ProductUnit $unit, array $data, ?int $userId): ProductUnit
    {
        return DB::transaction(function () use ($product, $unit, $data, $userId) {
            Product::query()->lockForUpdate()->findOrFail($product->id);
            $factor = (string) Qty::of((string) $data['factor']);

            if ($unit === null) {
                if (ProductUnit::query()->where('product_id', $product->id)->where('unit_id', (int) $data['unit_id'])->exists()) {
                    throw ValidationException::withMessages(['unit_id' => 'Satuan ini sudah ada di produk ini.']);
                }
                $target = new ProductUnit;
                $target->forceFill(['product_id' => $product->id, 'unit_id' => (int) $data['unit_id'], 'is_base' => false, 'created_by' => $userId]);
                $before = null;
            } else {
                $target = ProductUnit::query()->where('product_id', $product->id)->lockForUpdate()->findOrFail($unit->id);
                $before = $target->only(['factor', 'is_default_sales', 'is_default_purchase', 'is_active']);

                if (! Qty::of($target->factor)->isEqualTo($factor)) {
                    if ($target->is_base) {
                        throw ValidationException::withMessages(['factor' => 'Faktor satuan dasar selalu 1.']);
                    }
                    if (ProductUsage::unitUsed($target)) {
                        throw new ApiError(ErrorCode::UNIT_FACTOR_LOCKED, publicMessage: 'Faktor satuan ini sudah dipakai dokumen sehingga tidak bisa diubah. Buat satuan baru lalu nonaktifkan yang lama.');
                    }
                }
                if ($target->is_base && ! (bool) $data['is_active']) {
                    throw ValidationException::withMessages(['is_active' => 'Satuan dasar tidak bisa dinonaktifkan.']);
                }
            }

            $target->forceFill([
                'factor' => $target->is_base ? '1' : $factor,
                'is_default_sales' => (bool) $data['is_default_sales'],
                'is_default_purchase' => (bool) $data['is_default_purchase'],
                'is_active' => (bool) $data['is_active'],
                'updated_by' => $userId,
            ])->save();

            foreach (['is_default_sales', 'is_default_purchase'] as $default) {
                if ($target->{$default}) {
                    ProductUnit::query()->where('product_id', $product->id)->whereKeyNot($target->id)->where($default, true)
                        ->update([$default => false, 'updated_by' => $userId, 'updated_at' => now()]);
                }
            }

            $after = $target->only(['unit_id', 'factor', 'is_default_sales', 'is_default_purchase', 'is_active']);
            $this->audit->record($before === null ? 'product_unit.created' : 'product_unit.updated', $target, $before, $after);

            return $target;
        });
    }
}
