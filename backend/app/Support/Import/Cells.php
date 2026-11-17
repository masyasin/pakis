<?php

namespace App\Support\Import;

use App\Support\Money\Money;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Normalisasi sel bersama tipe impor master (T-085, BR-170). Setiap method mengembalikan null untuk
 * sel kosong dan melempar InvalidArgumentException berisi pesan siap tampil per sel.
 *
 * Nilai diawali `=`, `+`, `-`, `@` (pola formula) TIDAK pernah dievaluasi: pembaca hanya mengambil
 * nilai tersimpan, dan teks disimpan apa adanya; berkas galat & ekspor sudah meng-escape-nya.
 */
final class Cells
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

    public static function text(mixed $value, int $max = 255): ?string
    {
        $text = CellText::of($value);
        if ($text !== null && mb_strlen($text) > $max) {
            throw new InvalidArgumentException("Maksimal {$max} karakter.");
        }

        return $text;
    }

    /** Kode rujukan (SKU, kode kategori, kode area…): huruf besar, apostrof Excel & spasi tepi dibuang. */
    public static function code(mixed $value): ?string
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return (string) (int) $value;
        }
        $text = CellText::of($value);

        return $text === null ? null : mb_strtoupper(ltrim($text, "'"));
    }

    /** Kuantitas ≥ 0 (3 desimal). Format id-ID diterima: "1.250", "2,5", sel angka Excel. */
    public static function qty(mixed $value): ?string
    {
        return self::decimal($value, Qty::class, 'Jumlah');
    }

    /** Uang ≥ 0 (2 desimal): "1.250.000", "Rp 12.500", sel angka Excel. */
    public static function money(mixed $value): ?string
    {
        return self::decimal(is_string($value) ? (string) preg_replace('/^\s*rp\.?\s*/i', '', $value) : $value, Money::class, 'Nilai');
    }

    /** Biaya per satuan ≥ 0 (4 desimal, HPP): "12.500", "Rp 1.250,75", sel angka Excel. */
    public static function unitCost(mixed $value): ?string
    {
        return self::decimal(is_string($value) ? (string) preg_replace('/^\s*rp\.?\s*/i', '', $value) : $value, UnitCost::class, 'Biaya');
    }

    /** Bilangan bulat ≥ 0. */
    public static function int(mixed $value, int $max = PHP_INT_MAX): ?int
    {
        $qty = self::qty($value);
        if ($qty === null) {
            return null;
        }
        if (Qty::of($qty)->isInteger() === false) {
            throw new InvalidArgumentException('Harus bilangan bulat.');
        }
        $int = (int) Qty::of($qty)->toBigDecimal()->toInt();
        if ($int > $max) {
            throw new InvalidArgumentException("Maksimal {$max}.");
        }

        return $int;
    }

    /** Tanggal → `Y-m-d`: 31/12/2024, 31-12-2024, 2024-12-31, 31 Des 2024, serial Excel, sel tanggal. */
    public static function date(mixed $value): ?string
    {
        if (CellText::isBlank($value)) {
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

    /** Ya/tidak: ya, y, yes, benar, true, 1, ✓ / tidak, t, no, salah, false, 0. */
    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return match ((int) $value) {
                1 => true, 0 => false,
                default => throw new InvalidArgumentException('Isi ya atau tidak.'),
            };
        }
        $text = CellText::of($value);
        if ($text === null) {
            return null;
        }

        return match (mb_strtolower($text)) {
            'ya', 'y', 'yes', 'benar', 'true', '1', '✓', 'v', 'x' => true,
            'tidak', 't', 'no', 'n', 'salah', 'false', '0', '-' => false,
            default => throw new InvalidArgumentException('Isi ya atau tidak.'),
        };
    }

    /**
     * Nilai tampilan per kolom untuk `import_rows.data` (halaman pratinjau hanya menampilkan teks):
     * bool → ya/tidak, angka → teks. Nilai bertipe hasil validasi disimpan terpisah di kunci `parsed`.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    public static function display(array $values): array
    {
        return array_map(fn ($v) => match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'ya' : 'tidak',
            is_scalar($v) => (string) $v,
            default => null,
        }, $values);
    }

    /**
     * @param  class-string<Qty>|class-string<Money>|class-string<UnitCost>  $type
     */
    private static function decimal(mixed $value, string $type, string $label): ?string
    {
        if (CellText::isBlank($value)) {
            return null;
        }
        try {
            $parsed = is_int($value) || is_float($value)
                ? $type::of(rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'))
                : $type::fromInput((string) $value);
        } catch (MathException) {
            throw new InvalidArgumentException("{$label} harus berupa angka, mis. 1.250.000 atau 2,5.");
        }
        if ($parsed->isNegative()) {
            throw new InvalidArgumentException("{$label} tidak boleh negatif.");
        }

        return (string) $parsed;
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
