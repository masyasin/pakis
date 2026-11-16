<?php

use App\Domain\Employee\Support\NikCipher;
use App\Domain\Employee\Support\PersonName;
use App\Support\Phone\PhoneNormalizer;

test('BR-050 PhoneNormalizer: 08… / +62… / 62… / 8… → 62…; selain itu null', function (?string $input, ?string $expected) {
    expect(PhoneNormalizer::normalize($input))->toBe($expected);
})->with([
    ['0812-3456-7890', '6281234567890'],
    ['+62 812 3456 7890', '6281234567890'],
    ['6281234567890', '6281234567890'],
    ['812.3456.7890', '6281234567890'],
    ['(0291) 123456', '62291123456'],
    ['0812', null],
    ['+1 555 123 4567', null],
    ['0812abc4567', null],
    ['', null],
    [null, null],
    ['08123456789012345', null],
]);

test('BR-050 PhoneNormalizer::forSearch mengubah awalan 0 ke 62, teks lain apa adanya', function () {
    expect(PhoneNormalizer::forSearch('0812-34'))->toBe('6281234')
        ->and(PhoneNormalizer::forSearch('+62812'))->toBe('62812')
        ->and(PhoneNormalizer::forSearch('budi'))->toBe('budi');
});

test('BR-050 PersonName merapikan spasi tanpa mengubah huruf', function () {
    expect(PersonName::clean("  Budi \t  Santoso\n"))->toBe('Budi Santoso')
        ->and(PersonName::clean(null))->toBe('');
});

test('SEC-010 NikCipher: HMAC berkunci, deterministik, beda kunci beda hash; mask 4+8+4', function () {
    $nik = '99'.str_pad((string) random_int(0, 99_999_999_999_999), 14, '0', STR_PAD_LEFT);
    $a = new NikCipher(hash('sha256', 'kunci-a'));
    $b = new NikCipher(hash('sha256', 'kunci-b'));

    expect($a->hash($nik))->toBe($a->hash($nik))
        ->and($a->hash($nik))->not->toBe($b->hash($nik))
        ->and($a->hash($nik))->not->toBe(hash('sha256', $nik))
        ->and(strlen($a->hash($nik)))->toBe(64)
        ->and(NikCipher::mask($nik))->toBe(substr($nik, 0, 4).'********'.substr($nik, -4));
});

test('SEC-010 NikCipher fail-closed: kunci kosong / bukan 64 hex → exception', function (string $key) {
    expect(fn () => (new NikCipher($key))->hash('1'))->toThrow(RuntimeException::class);
})->with(['', 'pendek', str_repeat('z', 64)]);
