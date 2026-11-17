<?php

namespace App\Domain\Employee\Import;

use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Employee\Support\PersonName;
use App\Support\Import\Cells;
use App\Support\Import\CellText;
use App\Support\Phone\PhoneNormalizer;
use InvalidArgumentException;

/**
 * Mengubah nilai sel mentah menjadi nilai sistem (BR-054). Setiap method melempar
 * InvalidArgumentException berisi pesan galat yang siap ditampilkan per sel; null = sel kosong.
 */
final class RowNormalizer
{
    /** @var array<string, Gender> */
    private const GENDERS = [
        'l' => Gender::Male, 'lk' => Gender::Male, 'laki laki' => Gender::Male, 'lakilaki' => Gender::Male,
        'laki' => Gender::Male, 'lelaki' => Gender::Male, 'pria' => Gender::Male, 'male' => Gender::Male,
        'p' => Gender::Female, 'pr' => Gender::Female, 'perempuan' => Gender::Female, 'wanita' => Gender::Female,
        'female' => Gender::Female,
    ];

    public static function isBlank(mixed $value): bool
    {
        return CellText::isBlank($value);
    }

    /** Teks satu baris: spasi dirapikan (CellText, dipakai bersama kerangka impor). */
    public static function text(mixed $value): ?string
    {
        return CellText::of($value);
    }

    public static function name(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : PersonName::clean($text);
    }

    /** Kode/nomor (No. Pegawai, No. Atasan): sel angka Excel `1001.0` → `1001`, apostrof Excel dibuang. */
    public static function code(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : ltrim($text, "'");
    }

    /** Tanggal sel → `Y-m-d` (dipindah ke kerangka impor bersama di T-086; perilaku sama). */
    public static function date(mixed $value): ?string
    {
        return Cells::date($value);
    }

    public static function gender(mixed $value): ?Gender
    {
        if (self::isBlank($value)) {
            return null;
        }
        $key = EmployeeImportFields::normalizeHeader($value);

        return self::GENDERS[$key] ?? self::GENDERS[str_replace(' ', '', $key)]
            ?? throw new InvalidArgumentException('Jenis kelamin tidak dikenali. Gunakan L/P, Laki-laki/Perempuan, atau Pria/Wanita.');
    }

    /** Nomor HP → `62…`. Sel angka (`812…`, 0 di depan hilang di Excel) tetap diterima. */
    public static function phone(mixed $value): ?string
    {
        if (self::isBlank($value)) {
            return null;
        }
        $text = is_int($value) || is_float($value) ? sprintf('%.0f', $value) : ltrim(trim(is_scalar($value) ? (string) $value : ''), "'");

        return PhoneNormalizer::normalize($text)
            ?? throw new InvalidArgumentException('Nomor HP tidak valid. Gunakan nomor Indonesia 10–15 digit, contoh 0812-3456-7890.');
    }

    /**
     * NIK 16 digit. Sel ANGKA ditolak: Excel menyimpan angka maksimal 15 digit bermakna, jadi digit
     * terakhir NIK sudah berubah tanpa terlihat. Pesan galat tidak pernah memuat NIK (SEC-010).
     */
    public static function nik(mixed $value): ?string
    {
        if (self::isBlank($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            throw new InvalidArgumentException('NIK tersimpan sebagai angka sehingga digit terakhirnya bisa berubah. Format kolom NIK sebagai Teks lalu ketik ulang.');
        }

        $digits = (string) preg_replace('/[\s.\-]+/', '', ltrim(trim(is_scalar($value) ? (string) $value : ''), "'"));
        if (preg_match(NikCipher::PATTERN, $digits) !== 1) {
            throw new InvalidArgumentException(preg_match('/^\d+$/', $digits) === 1
                ? 'NIK harus 16 digit angka (sekarang '.strlen($digits).').'
                : 'NIK hanya boleh berisi angka, tepat 16 digit.');
        }

        return $digits;
    }

    public static function email(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }
        $email = mb_strtolower($text);
        if (mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Format email tidak valid.');
        }

        return $email;
    }
}
