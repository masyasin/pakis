<?php

namespace App\Domain\Purchasing\Support;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Hitung baris & total PO (BR-160) di server, tanpa float (ADR-101). Nilai dari klien tidak pernah
 * dipakai: baris = qty × harga × (1 − diskon%), dibulatkan half-up 2 desimal; subtotal = Σ qty×harga;
 * diskon = subtotal − Σ total baris; pajak 0 (diisi Paket F bila PKP).
 */
final class PurchaseOrderTotals
{
    /** @return array{gross: Money, total: Money} */
    public static function line(string $qty, string $unitPrice, string $discountPct): array
    {
        $gross = BigDecimal::of($qty)->multipliedBy($unitPrice);
        $net = $gross->multipliedBy(BigDecimal::of('100')->minus($discountPct))->dividedBy('100', 2, RoundingMode::HalfUp);

        return ['gross' => Money::of($gross), 'total' => Money::of($net)];
    }

    /**
     * @param  array<int, array{gross: Money, total: Money}>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     */
    public static function document(array $lines): array
    {
        $subtotal = Money::zero();
        $net = Money::zero();
        foreach ($lines as $line) {
            $subtotal = $subtotal->plus($line['gross']->toBigDecimal());
            $net = $net->plus($line['total']->toBigDecimal());
        }

        return [
            'subtotal' => (string) $subtotal,
            'discount_total' => (string) $subtotal->minus($net->toBigDecimal()),
            'tax_total' => '0.00',
            'grand_total' => (string) $net,
        ];
    }
}
