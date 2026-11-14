<?php

namespace App\Domain\Numbering;

use App\Domain\Numbering\Enums\ResetPeriod;
use Carbon\CarbonInterface;

/**
 * Pola nomor dokumen (BR-025).
 *
 * Token: `{YYYY}` `{YY}` `{MM}` `{DD}` `{LOC}` `{SEQ:n}` (n = 3–8, wajib tepat satu). Teks literal
 * (prefix, pemisah) ditulis langsung dan hanya boleh `A–Z 0–9 - / . _`. Hasil maksimal 30 karakter.
 * Pola harus memuat bagian tanggal yang sesuai periode reset, agar nomor setelah reset tidak bisa
 * sama dengan nomor periode sebelumnya.
 */
final class PatternParser
{
    public const MAX_LENGTH = 30;

    private const TOKEN = '/\{([A-Z]+)(?::(\d+))?\}/';

    private const LITERAL = '/^[A-Z0-9\-\/._]*$/';

    /** Panjang tetap token tanggal. */
    private const WIDTH = ['YYYY' => 4, 'YY' => 2, 'MM' => 2, 'DD' => 2];

    /**
     * Daftar galat (Bahasa Indonesia); kosong = pola sah.
     *
     * @param  int  $locationCodeLength  panjang kode lokasi terpanjang (untuk menghitung `{LOC}`)
     * @return list<string>
     */
    public static function errors(string $pattern, ResetPeriod $reset, int $locationCodeLength = 0): array
    {
        $errors = [];

        if (trim($pattern) === '') {
            return ['Pola wajib diisi.'];
        }

        preg_match_all(self::TOKEN, $pattern, $tokens, PREG_SET_ORDER);
        $literal = (string) preg_replace(self::TOKEN, '', $pattern);

        if (str_contains($literal, '{') || str_contains($literal, '}')) {
            $errors[] = 'Ada kurung kurawal yang tidak membentuk token yang dikenal.';
        } elseif (preg_match(self::LITERAL, $literal) !== 1) {
            $errors[] = 'Teks di luar token hanya boleh huruf besar A–Z, angka, dan - / . _ (tanpa spasi).';
        }

        $names = [];
        $seqCount = 0;
        foreach ($tokens as $token) {
            $name = $token[1];
            $width = isset($token[2]) ? (int) $token[2] : null;
            $names[] = $name;

            if ($name === 'PREFIX') {
                $errors[] = 'Token {PREFIX} tidak dipakai: tulis prefix langsung, mis. PJA-{YY}{SEQ:4}.';
            } elseif ($name === 'SEQ') {
                $seqCount++;
                if ($width === null || $width < 3 || $width > 8) {
                    $errors[] = 'Token {SEQ:n} butuh n antara 3 dan 8, mis. {SEQ:4}.';
                }
            } elseif (isset(self::WIDTH[$name]) || $name === 'LOC') {
                if ($width !== null) {
                    $errors[] = "Token {{$name}} tidak memakai angka.";
                }
            } else {
                $errors[] = "Token {{$name}} tidak dikenal.";
            }
        }

        if ($seqCount !== 1) {
            $errors[] = 'Pola wajib memuat tepat satu {SEQ:n}.';
        }

        $hasYear = in_array('YYYY', $names, true) || in_array('YY', $names, true);
        $missing = match ($reset) {
            ResetPeriod::Never => null,
            ResetPeriod::Yearly => $hasYear ? null : 'Reset tahunan wajib memuat {YYYY} atau {YY}.',
            ResetPeriod::Monthly => $hasYear && in_array('MM', $names, true) ? null : 'Reset bulanan wajib memuat tahun ({YYYY}/{YY}) dan {MM}.',
            ResetPeriod::Daily => $hasYear && in_array('MM', $names, true) && in_array('DD', $names, true)
                ? null
                : 'Reset harian wajib memuat tahun ({YYYY}/{YY}), {MM}, dan {DD}.',
        };
        if ($missing !== null) {
            $errors[] = $missing;
        }

        if ($errors === []) {
            $length = strlen($literal) + array_sum(array_map(fn (array $t) => match ($t[1]) {
                'SEQ' => (int) ($t[2] ?? 0),
                'LOC' => $locationCodeLength,
                default => self::WIDTH[$t[1]],
            }, $tokens));

            if ($length > self::MAX_LENGTH) {
                $errors[] = "Nomor yang dihasilkan bisa sepanjang {$length} karakter, maksimal ".self::MAX_LENGTH.'.';
            }
        }

        return $errors;
    }

    public static function usesLocation(string $pattern): bool
    {
        return str_contains($pattern, '{LOC}');
    }

    /**
     * Bentuk nomor. `$date` adalah tanggal LOKAL dokumen.
     */
    public static function format(string $pattern, int $number, CarbonInterface $date, ?string $locationCode = null): string
    {
        return (string) preg_replace_callback(self::TOKEN, fn (array $t) => match ($t[1]) {
            'YYYY' => $date->format('Y'),
            'YY' => $date->format('y'),
            'MM' => $date->format('m'),
            'DD' => $date->format('d'),
            'LOC' => strtoupper((string) $locationCode),
            'SEQ' => str_pad((string) $number, (int) ($t[2] ?? 0), '0', STR_PAD_LEFT),
            default => $t[0],
        }, $pattern);
    }
}
