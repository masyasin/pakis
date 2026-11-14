<?php

use App\Domain\Region\Enums\RegionLevel;
use App\Domain\Region\Import\RegionCode;

/*
 * OQ-003: kode sumber Sintech tanpa titik → kode 04 §2.7 bertitik (2/4/6 digit lalu desa 4 digit).
 */

test('kode tanpa titik diberi titik sesuai level', function (string $raw, string $expected, RegionLevel $level) {
    expect(RegionCode::normalize($raw))->toBe($expected)
        ->and(RegionCode::level($expected))->toBe($level);
})->with([
    ['33', '33', RegionLevel::Province],
    ['3319', '33.19', RegionLevel::Regency],
    ['331904', '33.19.04', RegionLevel::District],
    ['3319042001', '33.19.04.2001', RegionLevel::Village],
]);

test('kode yang sudah bertitik diterima apa adanya', function (string $code) {
    expect(RegionCode::normalize($code))->toBe($code);
})->with(['33', '33.19', '33.19.04', '33.19.04.2001']);

test('kode tidak sah ditolak', function (string $raw) {
    expect(RegionCode::normalize($raw))->toBeNull();
})->with(['', '3', '331', '33190', '33.1', '33.19.4', 'ab', '33.19.04.20011', "33'; DROP", '33 19']);

test('induk dari kode', function () {
    expect(RegionCode::parent('33.19.04.2001'))->toBe('33.19.04')
        ->and(RegionCode::parent('33.19'))->toBe('33')
        ->and(RegionCode::parent('33'))->toBeNull();
});
