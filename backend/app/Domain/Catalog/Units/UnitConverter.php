<?php

namespace App\Domain\Catalog\Units;

use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;
use InvalidArgumentException;

/**
 * Konversi qty dokumen ↔ satuan dasar (BR-150, ADR-102). Setiap qty dokumen disimpan dua kali:
 * `qty` (satuan baris) dan `qty_base = qty × factor`, dibulatkan 3 desimal half-up oleh Qty.
 * Satuan `integer_only` (botol, dus, sak, …) menolak qty pecahan dengan `QTY_NOT_INTEGER`.
 */
final class UnitConverter
{
    /** Qty satuan baris → satuan dasar. */
    public function toBase(Qty $qty, ProductUnitLike $unit): Qty
    {
        $this->assertAllowed($qty, $unit);

        return $qty->times(self::factor($unit));
    }

    /** Qty satuan dasar → satuan baris (mis. menampilkan stok dalam dus). */
    public function fromBase(Qty $base, ProductUnitLike $unit): Qty
    {
        return $base->dividedBy(self::factor($unit));
    }

    /** @throws ApiError `QTY_NOT_INTEGER` bila satuan hanya bilangan bulat dan qty pecahan */
    public function assertAllowed(Qty $qty, ProductUnitLike $unit): void
    {
        if ($unit->isIntegerOnly() && ! $qty->isInteger()) {
            throw new ApiError(
                ErrorCode::QTY_NOT_INTEGER,
                details: ['unit' => $unit->unitLabel(), 'qty' => (string) $qty],
                publicMessage: "Jumlah dalam satuan {$unit->unitLabel()} harus bilangan bulat.",
            );
        }
    }

    private static function factor(ProductUnitLike $unit): string
    {
        $factor = $unit->conversionFactor();
        if (preg_match('/^\d+(\.\d{1,3})?$/', $factor) !== 1 || Qty::of($factor)->isZero()) {
            throw new InvalidArgumentException("Faktor satuan tidak sah: \"{$factor}\".");
        }

        return $factor;
    }
}
