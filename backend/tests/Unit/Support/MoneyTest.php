<?php

use App\Support\Money\Money;
use App\Support\Money\Qty;
use App\Support\Money\UnitCost;

/*
 * ADR-101: uang DECIMAL(18,2), harga/biaya per satuan dasar DECIMAL(18,4), tanpa float (T-069).
 */

test('ADR-101 Money berskala 2, half-up', function () {
    expect((string) Money::of('10000'))->toBe('10000.00')
        ->and((string) Money::of('0.005'))->toBe('0.01')
        ->and((string) Money::of('0.004'))->toBe('0.00')
        ->and((string) Money::of('100')->dividedBy('3'))->toBe('33.33')
        ->and((string) Money::fromInput('1.250.000,50'))->toBe('1250000.50');
});

test('ADR-101 UnitCost berskala 4: harga per dus → per botol', function () {
    // Satu dus isi 24 botol seharga Rp 1.000.000 → Rp 41.666,6667 per botol.
    $perBottle = UnitCost::of('1000000')->dividedBy('24');
    expect((string) $perBottle)->toBe('41666.6667')
        // Kembali ke uang: 24 botol × biaya per botol, dibulatkan ke rupiah sen.
        ->and((string) Money::of($perBottle->times(Qty::of('24'))->toBigDecimal()))->toBe('1000000.00');
});

test('ADR-101 Money menolak float', function () {
    Money::of(1000.5);
})->throws(InvalidArgumentException::class);
