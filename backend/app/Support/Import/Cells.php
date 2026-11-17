<?php

namespace App\Support\Import;

use App\Support\Money\Money;
use App\Support\Money\Qty;
use Brick\Math\Exception\MathException;
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
     * @param  class-string<Qty>|class-string<Money>  $type
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
}
