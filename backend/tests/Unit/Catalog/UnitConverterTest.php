<?php

use App\Domain\Catalog\Units\ProductUnitLike;
use App\Domain\Catalog\Units\UnitConverter;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Money\Qty;

/*
 * BR-150 (T-069): konversi ke satuan dasar dengan objek satuan in-memory; tabel product_units
 * dibuat T-070.
 */

function unitOf(string $factor, bool $integerOnly, string $label = 'Dus'): ProductUnitLike
{
    return new class($factor, $integerOnly, $label) implements ProductUnitLike
    {
        public function __construct(private string $factor, private bool $integerOnly, private string $label) {}

        public function conversionFactor(): string
        {
            return $this->factor;
        }

        public function isIntegerOnly(): bool
        {
            return $this->integerOnly;
        }

        public function unitLabel(): string
        {
            return $this->label;
        }
    };
}

test('BR-150 toBase: qty × faktor, 3 desimal half-up', function () {
    $converter = new UnitConverter;

    expect((string) $converter->toBase(Qty::of('10'), unitOf('24', true)))->toBe('240.000')
        ->and((string) $converter->toBase(Qty::of('2.5'), unitOf('0.25', false, 'Kg')))->toBe('0.625')
        ->and((string) $converter->toBase(Qty::of('1.333'), unitOf('1.5', false, 'Liter')))->toBe('2.000');
});

test('BR-150 fromBase: satuan dasar → satuan baris, 3 desimal half-up', function () {
    $converter = new UnitConverter;

    expect((string) $converter->fromBase(Qty::of('240'), unitOf('24', true)))->toBe('10.000')
        ->and((string) $converter->fromBase(Qty::of('100'), unitOf('24', true)))->toBe('4.167');
});

test('BR-150 satuan integer_only menolak 1,5 dengan QTY_NOT_INTEGER', function () {
    try {
        (new UnitConverter)->toBase(Qty::fromInput('1,5'), unitOf('24', true, 'Dus'));
        $this->fail('Harus ditolak.');
    } catch (ApiError $e) {
        expect($e->errorCode)->toBe(ErrorCode::QTY_NOT_INTEGER)
            ->and($e->errorCode->status())->toBe(422)
            ->and($e->publicMessage)->toBe('Jumlah dalam satuan Dus harus bilangan bulat.');
    }

    // Satuan berat boleh pecahan.
    expect((string) (new UnitConverter)->toBase(Qty::fromInput('1,5'), unitOf('1000', false, 'Kg')))->toBe('1500.000');
});

test('BR-150 faktor satuan tidak sah ditolak', function (string $factor) {
    (new UnitConverter)->toBase(Qty::of('1'), unitOf($factor, false));
})->with(['nol' => '0', 'negatif' => '-2', 'empat desimal' => '1.2345', 'teks' => 'dus'])
    ->throws(InvalidArgumentException::class);
