<?php

use App\Domain\Settings\Validation\ContrastRatio;
use App\Domain\Settings\Validation\Semver;

/*
 * BR-027 (semver) dan BR-021 (kontras warna).
 */

test('BR-027 format semver', function (string $version, bool $valid) {
    expect(Semver::isValid($version))->toBe($valid);
})->with([
    ['1.0.0', true], ['10.20.300', true], ['0.0.1', true],
    ['1.0', false], ['v1.0.0', false], ['1.0.0-beta', false], ['01.0.0', false], ['', false],
]);

test('BR-027 perbandingan semver numerik, bukan teks', function () {
    expect(Semver::lessThan('1.9.0', '1.10.0'))->toBeTrue()
        ->and(Semver::lessThan('1.10.0', '1.9.0'))->toBeFalse()
        ->and(Semver::compare('2.0.0', '2.0.0'))->toBe(0);
});

test('BR-021 rasio kontras WCAG', function () {
    expect(round(ContrastRatio::between('#000000', '#FFFFFF'), 1))->toBe(21.0)
        ->and(ContrastRatio::between('#2F6B3B', '#FFFFFF'))->toBeGreaterThan(4.5)
        ->and(ContrastRatio::between('#9BE59B', '#FFFFFF'))->toBeLessThan(4.5);
});

test('BR-021 varian gelap dihitung dan memenuhi 4.5:1 di latar gelap', function () {
    $dark = ContrastRatio::darkVariant('#2F6B3B');

    expect($dark)->toMatch('/^#[0-9A-F]{6}$/')
        ->and(ContrastRatio::between($dark, ContrastRatio::DARK_BACKGROUND))->toBeGreaterThanOrEqual(4.5)
        ->and(ContrastRatio::problem('#2F6B3B'))->toBeNull()
        ->and(ContrastRatio::problem('#9BE59B'))->toContain('4.5:1');
});
