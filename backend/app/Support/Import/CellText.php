<?php

namespace App\Support\Import;

use DateTimeInterface;

/**
 * Sel mentah → teks satu baris, dipakai kerangka (judul kolom, contoh nilai) dan tipe impor.
 * Sel tanggal menjadi `Y-m-d`; angka bulat dari Excel (float) ditulis tanpa ".0".
 */
final class CellText
{
    public static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Teks satu baris: spasi dirapikan; null = sel kosong. */
    public static function of(mixed $value): ?string
    {
        if (self::isBlank($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));
    }
}
