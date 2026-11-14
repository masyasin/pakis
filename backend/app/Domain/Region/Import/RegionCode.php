<?php

namespace App\Domain\Region\Import;

use App\Domain\Region\Enums\RegionLevel;

/**
 * Kode wilayah 04 §2.7: `33`, `33.19`, `33.19.04`, `33.19.04.2001`.
 *
 * Sumber Sintech (OQ-003) menulis kode tanpa titik (`3319042001`); titik disisipkan setelah digit
 * ke-2/4/6. Kode yang sudah bertitik diterima apa adanya, agar format sumber lain mudah ditambah.
 */
final class RegionCode
{
    /** Pola kode bertitik yang sah untuk semua level. */
    public const PATTERN = '/^\d{2}(\.\d{2}(\.\d{2}(\.\d{4})?)?)?$/';

    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if (preg_match(self::PATTERN, $raw) === 1) {
            return $raw;
        }

        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return match (strlen($raw)) {
            2 => $raw,
            4 => substr($raw, 0, 2).'.'.substr($raw, 2, 2),
            6 => substr($raw, 0, 2).'.'.substr($raw, 2, 2).'.'.substr($raw, 4, 2),
            10 => substr($raw, 0, 2).'.'.substr($raw, 2, 2).'.'.substr($raw, 4, 2).'.'.substr($raw, 6, 4),
            default => null,
        };
    }

    /** Level dari kode bertitik yang sah. */
    public static function level(string $code): ?RegionLevel
    {
        if (preg_match(self::PATTERN, $code) !== 1) {
            return null;
        }

        return [1 => RegionLevel::Province, 2 => RegionLevel::Regency, 3 => RegionLevel::District, 4 => RegionLevel::Village][substr_count($code, '.') + 1];
    }

    /** Kode induk (buang segmen terakhir); null untuk provinsi. */
    public static function parent(string $code): ?string
    {
        $position = strrpos($code, '.');

        return $position === false ? null : substr($code, 0, $position);
    }
}
