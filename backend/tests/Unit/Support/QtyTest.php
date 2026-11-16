<?php

use App\Support\Money\Qty;

/*
 * ADR-101: kuantitas DECIMAL(18,3) tanpa float, pembulatan half-up deterministik (T-069).
 */

test('ADR-101 Qty::of("2.5")->times("24") = 60.000 tanpa float', function () {
    expect((string) Qty::of('2.5')->times('24'))->toBe('60.000')
        ->and((string) Qty::of(3))->toBe('3.000')
        ->and((string) Qty::of('0.1')->plus('0.2'))->toBe('0.300');
});

test('ADR-101 float ditolak, termasuk lewat operasi', function (Closure $call) {
    expect($call)->toThrow(InvalidArgumentException::class);
})->with([
    'of float' => [fn () => Qty::of(2.5)],
    'times float' => [fn () => Qty::of('1')->times(0.5)],
    'teks bukan angka' => [fn () => Qty::of('dua')],
    'notasi ilmiah' => [fn () => Qty::of('1e3')],
]);

test('ADR-101 pembulatan half-up ke 3 desimal', function () {
    expect((string) Qty::of('1.0005'))->toBe('1.001')
        ->and((string) Qty::of('1.0004'))->toBe('1.000')
        ->and((string) Qty::of('-1.0005'))->toBe('-1.001')
        ->and((string) Qty::of('10')->dividedBy('3'))->toBe('3.333')
        ->and((string) Qty::of('2')->dividedBy('3'))->toBe('0.667');
});

test('ADR-101 pembagi nol ditolak', function () {
    Qty::of('1')->dividedBy('0');
})->throws(InvalidArgumentException::class);

test('ADR-101 input id-ID: koma desimal, titik ribuan', function () {
    expect((string) Qty::fromInput('1,5'))->toBe('1.500')
        ->and((string) Qty::fromInput('1.234,5'))->toBe('1234.500')
        ->and((string) Qty::fromInput('1.500'))->toBe('1500.000')
        ->and((string) Qty::fromInput(' 2.5 '))->toBe('2.500')
        ->and((string) Qty::fromInput('12'))->toBe('12.000');
});

test('ADR-101 bilangan bulat, perbandingan, dan JSON', function () {
    expect(Qty::of('3.000')->isInteger())->toBeTrue()
        ->and(Qty::of('1.5')->isInteger())->toBeFalse()
        ->and(Qty::of('2')->compareTo('2.000'))->toBe(0)
        ->and(Qty::of('2')->isEqualTo(Qty::of('2.0')))->toBeTrue()
        ->and(Qty::zero()->isZero())->toBeTrue()
        ->and(Qty::of('-1')->isNegative())->toBeTrue()
        ->and(json_encode(['qty' => Qty::of('1.5')]))->toBe('{"qty":"1.500"}');
});
