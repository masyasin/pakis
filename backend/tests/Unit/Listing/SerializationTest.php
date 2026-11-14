<?php

use App\Models\User;
use App\Support\Api\RequestId;

/*
 * Bentuk prop Inertia (05 §3.2) dan respons API daftar (05 §3.3).
 */

beforeEach(function () {
    User::factory()->create(['name' => 'Agus']);
});

test('toInertia memakai bentuk 05 §3.2', function () {
    $props = runListing(['q' => 'agus', 'filter' => ['names' => ['Agus']]])
        ->through(fn ($u) => ['id' => $u->id, 'name' => $u->name])
        ->toInertia();

    expect(array_keys($props))->toBe(['rows', 'listing'])
        ->and($props['rows'][0])->toBe(['id' => $props['rows'][0]['id'], 'name' => 'Agus'])
        ->and(array_keys($props['listing']))->toBe(['pagination', 'state', 'spec'])
        ->and(array_keys($props['listing']['pagination']))->toBe(['page', 'per_page', 'total', 'last_page', 'from', 'to'])
        ->and(array_keys($props['listing']['state']))->toBe(['q', 'sort', 'filters', 'invalid_filters'])
        ->and(array_keys($props['listing']['spec']))->toBe([
            'key', 'search_placeholder', 'per_page_options', 'default_sort', 'sorts', 'filters', 'exportable',
        ]);

    expect($props['listing']['state'])->toMatchArray(['q' => 'agus', 'sort' => 'name'])
        ->and($props['listing']['spec']['key'])->toBe('test_users')
        ->and($props['listing']['spec']['per_page_options'])->toBe([10, 25, 50, 100])
        ->and($props['listing']['spec']['sorts'][0])->toBe(['key' => 'name', 'label' => 'Nama']);
});

test('spec filter diserialisasi per tipe', function () {
    $filters = collect(runListing()->toInertia()['listing']['spec']['filters'])->keyBy('key');

    expect($filters['name'])->toMatchArray(['type' => 'select', 'label' => 'Nama'])
        ->and($filters['name']['options'])->toBe([['value' => 'Agus', 'label' => 'Agus'], ['value' => 'Budi', 'label' => 'Budi']])
        ->and($filters['names']['type'])->toBe('multi_select')
        ->and($filters['verified']['type'])->toBe('boolean')
        ->and($filters['created_at']['type'])->toBe('date_range')
        ->and($filters['id_range']['type'])->toBe('number_range')
        // 05 §3.2: filter relasi = select/multi_select + options_source (opsi dari lookup).
        ->and($filters['user_id'])->toMatchArray(['type' => 'multi_select', 'options_source' => 'users'])
        ->and($filters['user_id'])->not->toHaveKey('options');
});

test('state kosong diserialisasi sebagai objek JSON, bukan array', function () {
    $json = json_encode(runListing()->toInertia()['listing']['state']);

    expect($json)->toContain('"filters":{}')->toContain('"invalid_filters":{}');
});

test('toApi memakai bentuk 05 §3.3 dengan request_id', function () {
    RequestId::assign('01JBX8Q6V7M3T2K9R4N5P6W8YZ');

    $body = runListing([], '/api/v1/uji')->through(fn ($u) => ['name' => $u->name])->toApi();

    expect(array_keys($body))->toBe(['data', 'meta'])
        ->and($body['data'])->toBe([['name' => 'Agus']])
        ->and($body['meta']['request_id'])->toBe('01JBX8Q6V7M3T2K9R4N5P6W8YZ')
        ->and(array_keys($body['meta']['pagination']))->toBe(['page', 'per_page', 'total', 'last_page'])
        ->and(array_keys($body['meta']['listing']))->toBe(['state', 'spec']);
});

test('query() mengembalikan query tersaring & tersortir tanpa paging (untuk ekspor)', function () {
    User::factory()->count(30)->create();

    $result = runListing(['per_page' => 10, 'filter' => ['verified' => '1']]);

    expect($result->query()->count())->toBe($result->paginator()->total())
        ->and($result->query()->count())->toBeGreaterThan(10);
});
