<?php

namespace App\Domain\Settings\Validation;

/**
 * Versi semantik sederhana `MAJOR.MINOR.PATCH` untuk Pakis Pro (BR-027). Dipakai juga oleh
 * middleware gerbang versi API (T-031).
 */
final class Semver
{
    private const PATTERN = '/^(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})\.(0|[1-9]\d{0,3})$/';

    public static function isValid(?string $version): bool
    {
        return $version !== null && preg_match(self::PATTERN, $version) === 1;
    }

    /**
     * @return int -1 bila $a < $b, 0 bila sama, 1 bila $a > $b
     */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    public static function lessThan(string $a, string $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    private static function normalize(string $version): string
    {
        return self::isValid($version) ? $version : '0.0.0';
    }
}
