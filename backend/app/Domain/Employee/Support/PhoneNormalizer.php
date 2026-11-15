<?php

namespace App\Domain\Employee\Support;

/**
 * Nomor HP (BR-050): disimpan seragam `62…` agar pencarian, impor, dan WhatsApp memakai satu
 * format. Menerima `08…`, `+62…`, `62…`, `8…`; spasi, titik, tanda hubung, dan kurung dibuang.
 */
final class PhoneNormalizer
{
    public const MIN_DIGITS = 10;

    public const MAX_DIGITS = 15;

    /** Hasil `62…` atau null bila bukan nomor Indonesia yang sah. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $raw = trim($input);
        $plus = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/[\s.\-()]+/', '', $plus ? substr($raw, 1) : $raw);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return null;
        }

        $digits = match (true) {
            str_starts_with($digits, '62') => $digits,
            $plus => '', // +kode negara lain: bukan nomor Indonesia
            str_starts_with($digits, '0') => '62'.substr($digits, 1),
            str_starts_with($digits, '8') => '62'.$digits,
            default => '',
        };

        $length = strlen($digits);

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS ? $digits : null;
    }

    /**
     * Untuk kotak pencarian: ubah awalan `08`/`+62` ke `62` agar "0812" menemukan "62812…".
     * Input bukan angka dikembalikan apa adanya.
     */
    public static function forSearch(string $term): string
    {
        $digits = (string) preg_replace('/[\s.\-()+]+/', '', $term);

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return $term;
        }

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }
}
