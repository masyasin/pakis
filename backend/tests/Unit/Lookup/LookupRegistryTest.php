<?php

use App\Domain\Reference\Lookup\ReferenceLookup;
use App\Support\Lookup\LookupRegistry;
use Tests\TestCase;

/*
 * Sumber lookup didaftarkan per modul (T-011). Kunci dobel = kesalahan program, bukan ditimpa diam-diam.
 */

uses(TestCase::class);

test('sumber terdaftar bisa di-resolve, yang tidak terdaftar null', function () {
    $registry = new LookupRegistry;
    $registry->register('references', ReferenceLookup::class);

    expect($registry->resolve('references'))->toBeInstanceOf(ReferenceLookup::class)
        ->and($registry->resolve('locations'))->toBeNull()
        ->and($registry->keys())->toBe(['references']);
});

test('kunci dobel ditolak', function () {
    $registry = new LookupRegistry;
    $registry->register('references', ReferenceLookup::class);

    expect(fn () => $registry->register('references', ReferenceLookup::class))
        ->toThrow(LogicException::class, 'sudah terdaftar');
});

test('kunci lookup hanya huruf kecil & garis bawah (dipakai di URL)', function () {
    expect(fn () => (new LookupRegistry)->register('Ref-Items', ReferenceLookup::class))
        ->toThrow(LogicException::class);
});

test('sumber bawaan aplikasi terdaftar', function () {
    expect(app(LookupRegistry::class)->keys())->toContain('references', 'users');
});
