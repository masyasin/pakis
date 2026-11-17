<?php

namespace App\Support\Phone;

/**
 * Nomor HP (BR-050, BR-166(1)): disimpan seragam `62…` agar pencarian, impor, WhatsApp, dan
 * perbandingan HP kios ↔ pegawai ↔ akun memakai satu format. Menerima `08…`, `+62…`, `62…`, `8…`;
 * spasi, titik, tanda hubung, dan kurung dibuang.
 *
 * Satu-satunya normalizer HP di aplikasi (dipindah dari `Domain\Employee\Support` di T-073, PA-6/
 * PA-23): Employee, akun (`users.phone`), dan Customer memakai kelas ini agar hasil identik.
 */
final class PhoneNormalizer
{
    public const MIN_DIGITS = 10;

    public const MAX_DIGITS = 15;

    /** Bentuk tersimpan yang sah (hasil normalize): `62` + 8–13 digit = 10–15 digit. */
    public const STORED_PATTERN = '/^62[0-9]{8,13}$/';

    public const INVALID_MESSAGE = 'Nomor HP tidak sah. Pakai nomor Indonesia 10–15 digit, mis. 0812 3456 7890.';

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
     * Untuk `prepareForValidation` form: kosong → null; nomor sah → `62…`; selain itu dikembalikan
     * tanpa pemisah tampilan agar aturan `STORED_PATTERN` menolaknya dengan pesan yang jelas
     * (bukan diam-diam dikosongkan).
     */
    public static function forInput(mixed $input): mixed
    {
        if (! is_string($input)) {
            return $input;
        }

        if (trim($input) === '') {
            return null;
        }

        return self::normalize($input) ?? (string) preg_replace('/[\s.\-()]+/', '', $input);
    }

    /**
     * Tampilan termasking (SEC-108): 4 digit awal (bentuk lokal `08…`) + 5 titik + 3 digit akhir,
     * mis. `0812•••••789` untuk `628123456789`.
     * Jumlah titik tetap agar panjang nomor asli tidak terbaca. Nomor terlalu pendek (< 8 digit,
     * data lama tak sah) seluruhnya diganti titik supaya tidak ada digit tengah yang bocor.
     */
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 8) {
            return '•••••';
        }
        // Tampilan memakai awalan lokal `08…` seperti HP penuh di layar (0812-…), bukan bentuk
        // simpan `62…`, agar nomor termasking & nomor penuh terbaca sama (keputusan Mas 17 Nov 2026).
        $local = str_starts_with($digits, '62') ? '0'.substr($digits, 2) : $digits;

        return substr($local, 0, 4).'•••••'.substr($local, -3);
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
