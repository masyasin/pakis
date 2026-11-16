<?php

namespace App\Domain\Settings;

/**
 * Angka desimal sebagai string untuk setting tipe `decimal` & `money` (PA-19).
 *
 * Mengapa bukan float: nilai rupiah DECIMAL(18,2) dan ambang persen dibandingkan dengan nominal
 * dokumen; float membulatkan (mis. 0.1 + 0.2) sehingga batas inklusif bisa meleset satu sen.
 * Tidak memakai bcmath karena ekstensi itu tidak diwajibkan di composer.json.
 */
final class DecimalString
{
    /** Bentuk kanonik yang diterima: tanda minus opsional, digit, titik desimal opsional. */
    public const PATTERN = '/^-?\d+(\.\d+)?$/';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Rapikan ke bentuk kanonik: buang nol di depan & (bila $scale null) nol di belakang koma;
     * dengan $scale, pecahan diisi/dipotong tepat sejumlah digit itu (money = 2).
     * Nilai yang bukan angka desimal dikembalikan apa adanya (validasi yang menolaknya).
     */
    public static function normalize(string $value, ?int $scale = null): string
    {
        if (! self::isValid($value)) {
            return $value;
        }

        $negative = str_starts_with($value, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');

        $frac = $scale === null
            ? rtrim($frac, '0')
            : substr(str_pad($frac, $scale, '0'), 0, $scale);

        $result = $frac === '' ? $int : $int.'.'.$frac;

        return $negative && trim($result, '0.') !== '' ? '-'.$result : $result;
    }

    /** -1, 0, atau 1 seperti `<=>`, untuk dua string desimal valid. */
    public static function compare(string $a, string $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        $negA = str_starts_with($a, '-');
        $negB = str_starts_with($b, '-');

        if ($negA !== $negB) {
            return $negA ? -1 : 1;
        }

        $magnitude = self::compareMagnitude(ltrim($a, '-'), ltrim($b, '-'));

        return $negA ? -$magnitude : $magnitude;
    }

    private static function compareMagnitude(string $a, string $b): int
    {
        [$intA, $fracA] = array_pad(explode('.', $a, 2), 2, '');
        [$intB, $fracB] = array_pad(explode('.', $b, 2), 2, '');

        if (strlen($intA) !== strlen($intB)) {
            return strlen($intA) <=> strlen($intB);
        }
        if ($intA !== $intB) {
            return strcmp($intA, $intB) <=> 0;
        }

        $length = max(strlen($fracA), strlen($fracB));

        return strcmp(str_pad($fracA, $length, '0'), str_pad($fracB, $length, '0')) <=> 0;
    }
}
