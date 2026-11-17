<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Models\ProductStockLevel;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Simpan stok minimum/maksimum satu produk di satu lokasi stok (15 §4.8 `product_stock_levels`,
 * dipakai alert stok & saran PO). Satu baris per (produk, lokasi) — upsert. Qty satuan dasar, string
 * desimal (ADR-101). Maks kosong = tanpa batas atas; maks < min ditolak (CHECK `psl_qty_chk` = lapis
 * kedua). Dipakai impor master `stock_levels` (T-085); layar `admin.stock-levels.*` menyusul.
 */
final class SaveStockLevel
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{level: ProductStockLevel, changed: bool, created: bool} */
    public function handle(int $productId, int $stockLocationId, string $min, ?string $max, ?int $userId): array
    {
        if ($max !== null && BigDecimal::of($max)->isLessThan($min)) {
            throw ValidationException::withMessages(['max_qty_base' => 'Stok maksimum tidak boleh lebih kecil dari minimum.']);
        }

        return DB::transaction(function () use ($productId, $stockLocationId, $min, $max, $userId) {
            $level = ProductStockLevel::query()->where('product_id', $productId)->where('stock_location_id', $stockLocationId)->lockForUpdate()->first();
            $created = $level === null;
            $before = $created ? null : ['min_qty_base' => $level->min_qty_base, 'max_qty_base' => $level->max_qty_base];

            if (! $created && BigDecimal::of($level->min_qty_base)->isEqualTo($min)
                && (($level->max_qty_base === null && $max === null) || ($level->max_qty_base !== null && $max !== null && BigDecimal::of($level->max_qty_base)->isEqualTo($max)))) {
                return ['level' => $level, 'changed' => false, 'created' => false];
            }

            $level ??= (new ProductStockLevel)->forceFill(['product_id' => $productId, 'stock_location_id' => $stockLocationId, 'created_by' => $userId]);
            $level->forceFill(['min_qty_base' => $min, 'max_qty_base' => $max, 'updated_by' => $userId])->save();
            $this->audit->record('stock_level.saved', $level, $before, [
                'product_id' => $productId, 'stock_location_id' => $stockLocationId, 'min_qty_base' => $level->min_qty_base, 'max_qty_base' => $level->max_qty_base,
            ]);

            return ['level' => $level, 'changed' => true, 'created' => $created];
        });
    }
}
