<?php

namespace App\Domain\Pricing\Support;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pembulatan harga hasil hitung (salin versi ±%, promo T-076) menurut `pricing.rounding`: ke ATAS
 * ke kelipatan 100/500/1000 rupiah, atau dua desimal half-up bila `none`. Semua lewat BigDecimal
 * (ADR-101): tidak ada float di jalur uang.
 */
final class PriceRounding
{
    public static function apply(Money $price): Money
    {
        return self::roundUp($price, (string) setting('pricing.rounding'));
    }

    /**
     * Versi murni (tanpa membaca setting) untuk PromotionEngine & fixture lintas platform:
     * `none` = apa adanya (sudah 2 desimal), selain itu ke ATAS ke kelipatan 100/500/1000.
     */
    public static function roundUp(Money $price, string $mode): Money
    {
        if ($mode === 'none' || $price->isZero()) {
            return $price;
        }

        $step = BigDecimal::of($mode);

        return Money::of($price->toBigDecimal()->dividedBy($step, 0, RoundingMode::Up)->multipliedBy($step));
    }

    /**
     * Harga × (100 + persen) / 100, lalu dibulatkan. Persen string desimal ("5", "-2.5"); validasi
     * memastikan ≥ -100 sehingga hasil tidak negatif.
     */
    public static function adjust(Money $price, string $percent): Money
    {
        $factor = BigDecimal::of('100')->plus(BigDecimal::of($percent))->dividedBy('100', 6, RoundingMode::HalfUp);

        return self::apply($price->times($factor));
    }
}
