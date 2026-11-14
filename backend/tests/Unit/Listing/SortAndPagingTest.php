<?php

use App\Models\User;

/*
 * BR-001 (paging) dan BR-004 (sort).
 */

test('BR-004 sort naik dan turun pada key di spec', function () {
    foreach (['Citra', 'Agus', 'Budi'] as $name) {
        User::factory()->create(['name' => $name]);
    }

    expect(listedNames(['sort' => 'name']))->toBe(['Agus', 'Budi', 'Citra'])
        ->and(listedNames(['sort' => '-name']))->toBe(['Citra', 'Budi', 'Agus']);
});

test('BR-004 sort tidak dikenal kembali ke default', function (string $sort) {
    foreach (['Citra', 'Agus'] as $name) {
        User::factory()->create(['name' => $name]);
    }

    $result = runListing(['sort' => $sort]);

    expect(collect($result->paginator()->items())->pluck('name')->all())->toBe(['Agus', 'Citra'])
        ->and($result->state()['sort'])->toBe('name');
})->with(['password', '-password', ';drop table users', '--name', 'name;', '']);

test('BR-004 tie-breaker id desc membuat urutan stabil saat nilai sama', function () {
    $first = User::factory()->create(['name' => 'Sama']);
    $second = User::factory()->create(['name' => 'Sama']);
    $third = User::factory()->create(['name' => 'Sama']);

    $ids = collect(runListing(['sort' => 'name'])->paginator()->items())->pluck('id')->all();

    expect($ids)->toBe([$third->id, $second->id, $first->id]);
});

test('BR-001 per_page hanya dari opsi, selain itu default', function (mixed $perPage, int $expected) {
    $result = runListing(['per_page' => $perPage]);

    expect($result->paginator()->perPage())->toBe($expected);
})->with([
    'opsi sah' => [50, 50],
    'bukan opsi' => [7, 25],
    'raksasa' => [100000, 25],
    'negatif' => [-10, 25],
    'teks' => ['semua', 25],
]);

test('BR-001 page tidak valid kembali ke 1', function (mixed $page) {
    expect(runListing(['page' => $page])->paginator()->currentPage())->toBe(1);
})->with([0, -3, 'abc', '1.5']);

test('BR-001 paging server-side membagi hasil', function () {
    User::factory()->count(12)->create();

    $result = runListing(['per_page' => 10, 'page' => 2]);

    expect($result->paginator()->items())->toHaveCount(2)
        ->and($result->pagination())->toMatchArray([
            'page' => 2, 'per_page' => 10, 'total' => 12, 'last_page' => 2, 'from' => 11, 'to' => 12,
        ]);
});

test('BR-001 route API memakai opsi mobile [20, 50] dengan default 20', function () {
    $result = runListing([], '/api/v1/uji');

    expect($result->paginator()->perPage())->toBe(20)
        ->and($result->spec()->toArray()['per_page_options'])->toBe([20, 50]);
});
