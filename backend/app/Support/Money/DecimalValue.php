<?php

namespace App\Support\Money;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Perilaku bersama nilai desimal berskala tetap (ADR-101): immutable, di atas BigDecimal, dibulatkan
 * half-up ke SCALE pemakai trait. Float SENGAJA tidak diterima: pembulatan biner float membuat
 * faktur, PPN, dan HPP tidak deterministik. Nilai masuk lewat string ("2.5"), int, atau BigNumber.
 *
 * Pemakai mendefinisikan `private const SCALE`.
 *
 * @phpstan-require-implements JsonSerializable
 * @phpstan-require-implements Stringable
 */
trait DecimalValue
{
    private function __construct(private readonly BigDecimal $value) {}

    /**
     * Dari string kanonik ("1234.5"), int, atau BigNumber; dibulatkan half-up ke skala tipe ini.
     * Parameter `mixed` dengan pemeriksaan eksplisit, karena tanpa `strict_types` di pemanggil PHP
     * diam-diam mengubah float 2.5 menjadi "2.5" pada parameter `int|string`.
     */
    public static function of(mixed $value): static
    {
        return new static(self::operand($value)->toScale(self::SCALE, RoundingMode::HalfUp));
    }

    /**
     * Dari ketikan pengguna format id-ID: koma = desimal, titik = ribuan ("1.234,5" → 1234.5).
     * Tanpa koma, satu titik dengan 1–2 atau ≥ 4 digit di belakangnya dianggap desimal kanonik
     * ("2.5"), sedangkan pola ribuan murni ("1.500", "12.000.000") dianggap ribuan.
     */
    public static function fromInput(string $input): static
    {
        $text = str_replace([' ', "\u{00A0}"], '', trim($input));
        if (str_contains($text, ',')) {
            $text = str_replace(',', '.', str_replace('.', '', $text));
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text) === 1) {
            $text = str_replace('.', '', $text);
        }

        return static::of($text);
    }

    public static function zero(): static
    {
        return new static(BigDecimal::zero()->toScale(self::SCALE));
    }

    public function plus(mixed $other): static
    {
        return new static($this->value->plus(self::operand($other))->toScale(self::SCALE, RoundingMode::HalfUp));
    }

    public function minus(mixed $other): static
    {
        return new static($this->value->minus(self::operand($other))->toScale(self::SCALE, RoundingMode::HalfUp));
    }

    /** Kali dengan faktor (mis. faktor satuan "24"); hasil dibulatkan half-up ke skala tipe ini. */
    public function times(mixed $factor): static
    {
        return new static($this->value->multipliedBy(self::operand($factor))->toScale(self::SCALE, RoundingMode::HalfUp));
    }

    /** Bagi; hasil dibulatkan half-up ke skala tipe ini. Pembagi nol → InvalidArgumentException. */
    public function dividedBy(mixed $divisor): static
    {
        $by = self::operand($divisor);
        if ($by->isZero()) {
            throw new InvalidArgumentException('Pembagi tidak boleh nol.');
        }

        return new static($this->value->dividedBy($by, self::SCALE, RoundingMode::HalfUp));
    }

    public function compareTo(mixed $other): int
    {
        return $this->value->compareTo(self::operand($other));
    }

    public function isEqualTo(mixed $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    /** Tanpa bagian pecahan (dipakai satuan `integer_only`, BR-150). */
    public function isInteger(): bool
    {
        return $this->value->getFractionalPart()->isZero();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /** Bentuk kanonik berskala tetap, mis. "60.000"; aman disimpan ke kolom DECIMAL. */
    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): string
    {
        return (string) $this->value;
    }

    /** Qty/Money/UnitCost, BigNumber, int, atau string kanonik → BigDecimal. Float & lainnya ditolak. */
    private static function operand(mixed $value): BigDecimal
    {
        if ($value instanceof Qty || $value instanceof Money || $value instanceof UnitCost) {
            return $value->toBigDecimal();
        }
        if (is_float($value)) {
            throw new InvalidArgumentException('Nilai float tidak diterima (ADR-101); kirim sebagai string, mis. "2.5".');
        }
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
                throw new InvalidArgumentException("Bukan angka desimal yang sah: \"{$value}\".");
            }
        }
        if (! $value instanceof BigNumber && ! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Tipe nilai desimal tidak didukung: '.get_debug_type($value).'.');
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }
    }
}
