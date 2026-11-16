<?php

namespace App\Domain\Employee\Import;

use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Support\NikCipher;
use App\Domain\Employee\Support\PersonName;
use App\Support\Import\CellText;
use App\Support\Phone\PhoneNormalizer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Mengubah nilai sel mentah menjadi nilai sistem (BR-054). Setiap method melempar
 * InvalidArgumentException berisi pesan galat yang siap ditampilkan per sel; null = sel kosong.
 */
final class RowNormalizer
{
    /** Serial Excel (sistem 1900): hari sejak 1899-12-30, termasuk "bug" 29 Feb 1900 milik Excel. */
    private const EXCEL_EPOCH = '1899-12-30';

    /** Batas serial yang masuk akal (1 Jan 1900 s.d. 31 Des 9999 di Excel). */
    private const EXCEL_SERIAL_MAX = 2958465;

    /** @var array<string, int> */
    private const MONTHS = [
        'januari' => 1, 'jan' => 1, 'january' => 1,
        'februari' => 2, 'pebruari' => 2, 'feb' => 2, 'peb' => 2, 'february' => 2,
        'maret' => 3, 'mar' => 3, 'march' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'jun' => 6, 'june' => 6,
        'juli' => 7, 'jul' => 7, 'july' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'aug' => 8, 'august' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'oct' => 10, 'october' => 10,
        'november' => 11, 'nov' => 11, 'nop' => 11,
        'desember' => 12, 'des' => 12, 'dec' => 12, 'december' => 12,
    ];

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

    public static function date(mixed $value): ?string
    {
        if (self::isBlank($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value)) {
            return self::fromSerial($value);
        }

        $text = trim(ltrim(mb_strtolower(is_scalar($value) ? (string) $value : ''), "'"));
        // "2024-01-31 00:00:00" dari ekspor sistem lain: bagian jam diabaikan.
        $text = (string) preg_replace('/[ t]\d{1,2}:\d{2}(:\d{2})?$/', '', $text);

        if (preg_match('/^\d{5}$/', $text) === 1) {
            return self::fromSerial((int) $text);
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $text, $m) === 1) {
            return self::build((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})[\/.\-](\d{1,2})[\/.\-](\d{1,2})$/', $text, $m) === 1) {
            return self::build((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\s\-]+([a-z]+)\.?[\s\-]+(\d{4})$/u', $text, $m) === 1 && isset(self::MONTHS[$m[2]])) {
            return self::build((int) $m[3], self::MONTHS[$m[2]], (int) $m[1]);
        }

        throw new InvalidArgumentException('Format tanggal tidak dikenali. Contoh yang diterima: 31/12/2024, 2024-12-31, 31 Des 2024.');
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

    private static function fromSerial(int|float $serial): string
    {
        $days = (int) floor($serial);
        if ($days < 1 || $days > self::EXCEL_SERIAL_MAX) {
            throw new InvalidArgumentException('Format tanggal tidak dikenali. Contoh yang diterima: 31/12/2024, 2024-12-31, 31 Des 2024.');
        }

        return CarbonImmutable::parse(self::EXCEL_EPOCH)->addDays($days)->toDateString();
    }

    private static function build(int $year, int $month, int $day): string
    {
        if ($year < 1900 || ! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Tanggal tidak ada di kalender (mis. 31/02). Periksa hari dan bulan.');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
