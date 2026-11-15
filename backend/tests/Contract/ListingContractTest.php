<?php

use App\Models\User;
use App\Support\Listing\ListingDefaults;
use App\Support\Listing\ListingSpec;
use App\Support\Time\DisplayTime;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Contract\ListingRouteFixtures;
use Tests\Contract\Support\ListingContract;
use Tests\Fixtures\Listing\CompliantListingController;
use Tests\Fixtures\Listing\NonCompliantListingController;
use Tests\TestCase;

/*
 * Penjaga "paging/cari/filter/sort jangan terlewat" (BR-001–BR-004, 10 §3).
 * Route `admin.*.index` / `api.v1.*.index` yang tidak patuh membuat CI merah.
 */

/** @return list<string> */
function catalogKeys(): array
{
    return require base_path('tests/Fixtures/listing_catalog.php');
}

/**
 * Nilai contoh valid untuk filter pertama di spec (10 §3.2).
 */
function sampleFilterValue(ListingSpec $spec, string $routeName): ?array
{
    $samples = ListingRouteFixtures::filterSamples($routeName);

    foreach ($spec->toArray()['filters'] as $filter) {
        if (array_key_exists($filter['key'], $samples)) {
            return [$filter['key'], $samples[$filter['key']]];
        }

        $value = match ($filter['type']) {
            'select' => $filter['options'][0]['value'] ?? null,
            'multi_select' => isset($filter['options'][0]) ? [$filter['options'][0]['value']] : null,
            'boolean' => '1',
            'date_range' => ['from' => DisplayTime::toLocal(now())->toDateString()],
            'number_range' => ['min' => '0'],
            default => null,
        };

        if ($value !== null) {
            return [$filter['key'], $value];
        }
    }

    return null;
}

/**
 * @param  array<string, mixed>  $query
 * @return array{state: array<string, mixed>, pagination: array<string, mixed>}
 */
function fetchListing(TestCase $test, Route $route, array $query): array
{
    $name = (string) $route->getName();
    $url = route($name, ListingRouteFixtures::parameters($name)).'?'.http_build_query($query);

    $isApi = str_starts_with($name, 'api.v1.');
    if ($isApi) {
        // Token + perangkat asli (bukan actingAs): grup API memeriksa perangkat terikat (BR-040).
        app('auth')->forgetGuards();
        /** @var TestResponse $response */
        $response = $test->getJson($url, ListingRouteFixtures::apiHeaders($name));
    } else {
        // Request API sebelumnya memindahkan guard default ke sanctum (auth:sanctum → shouldUse).
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        $test->actingAs(ListingRouteFixtures::actor($name), 'web');
        /** @var TestResponse $response */
        $response = $test->get($url);
    }
    $response->assertOk();

    if ($isApi) {
        return ['state' => $response->json('meta.listing.state'), 'pagination' => $response->json('meta.pagination')];
    }

    $props = AssertableInertia::fromTestResponse($response)->toArray()['props'];

    return ['state' => $props['listing']['state'], 'pagination' => $props['listing']['pagination']];
}

/**
 * 10 §3.2: parameter sah dipakai, parameter jahat diabaikan (SEC-032).
 */
function assertListingRouteBehaves(TestCase $test, Route $route): void
{
    $name = (string) $route->getName();
    $spec = ListingContract::spec($route);
    expect($spec)->not->toBeNull();

    $array = $spec->toArray();
    $perPage = min($array['per_page_options']);
    $sort = $array['sorts'][0]['key'];
    $query = ['q' => 'zz', 'per_page' => $perPage, 'sort' => $sort];

    $sample = sampleFilterValue($spec, $name);
    if ($sample !== null) {
        $query['filter'] = [$sample[0] => $sample[1]];
    }

    $ok = fetchListing($test, $route, $query);
    expect($ok['pagination']['per_page'])->toBe($perPage, "{$name}: per_page")
        ->and($ok['state']['q'])->toBe('zz', "{$name}: state.q")
        ->and($ok['state']['sort'])->toBe($sort, "{$name}: state.sort");
    if ($sample !== null) {
        expect((array) $ok['state']['filters'])->toHaveKey($sample[0], message: "{$name}: filter contoh tidak diterapkan");
    }

    $attacked = fetchListing($test, $route, [
        'sort' => 'password',
        'filter' => ['tidak_ada' => '1'],
        'per_page' => 100000,
        'q' => str_repeat('x', 500),
    ]);
    $defaultPerPage = str_starts_with($name, 'api.v1.') ? $spec->forApi()->defaultPerPageValue() : $spec->defaultPerPageValue();

    expect($attacked['state']['sort'])->toBe($array['default_sort'], "{$name}: sort jahat harus kembali default")
        ->and((array) $attacked['state']['filters'])->not->toHaveKey('tidak_ada')
        ->and((array) $attacked['state']['invalid_filters'])->not->toHaveKey('tidak_ada')
        ->and($attacked['pagination']['per_page'])->toBe($defaultPerPage, "{$name}: per_page jahat harus default")
        ->and(mb_strlen($attacked['state']['q']))->toBe(ListingDefaults::SEARCH_MAX_LENGTH, "{$name}: q dipotong");
}

test('BR-001 setiap route *.index memakai standar listing', function () {
    $violations = ListingContract::routes()
        ->flatMap(fn (Route $route) => ListingContract::violations($route, catalogKeys()))
        ->all();

    expect($violations)->toBe([], implode("\n", $violations));
});

test('katalog listing dua arah: fixture ⊆ 07 §3.4 dan cocok dengan route', function () {
    $documented = ListingContract::documentedCatalog();
    $catalog = catalogKeys();

    // Katalog dokumen terbaca (pengaman bila format tabel 07 berubah).
    expect(count($documented))->toBeGreaterThan(20);

    foreach ($catalog as $key) {
        expect($documented)->toHaveKey($key, message: "Kunci [{$key}] di fixture tidak ada di 07 §3.4.");
        expect(RouteFacade::has($documented[$key]))->toBeTrue("Kunci [{$key}]: route {$documented[$key]} belum terdaftar.");
    }

    $routeKeys = ListingContract::routes()
        ->mapWithKeys(fn (Route $r) => [(string) $r->getName() => ListingContract::spec($r)?->key])
        ->all();

    foreach ($routeKeys as $routeName => $key) {
        expect(in_array($key, $catalog, true))->toBeTrue("Route {$routeName}: kunci [{$key}] belum ada di fixture katalog.");
        expect($documented[$key] ?? null)->toBe($routeName, "Kunci [{$key}] di 07 §3.4 menunjuk route lain.");
    }
});

test('SEC-032 setiap route daftar menerapkan parameter sah dan mengabaikan parameter jahat', function () {
    foreach (ListingContract::routes() as $route) {
        assertListingRouteBehaves($this, $route);
    }

    expect(true)->toBeTrue();
});

describe('meta-test: kontrak benar-benar menolak route yang tidak patuh', function () {
    beforeEach(function () {
        RouteFacade::middleware(['web', 'auth'])->group(function () {
            RouteFacade::get('admin/__kontrak-buruk', [NonCompliantListingController::class, 'index'])
                ->name('admin.__kontrak_buruk.index');
            RouteFacade::get('admin/__kontrak-patuh', [CompliantListingController::class, 'index'])
                ->name('admin.__kontrak_patuh.index');
        });
        RouteFacade::getRoutes()->refreshNameLookups();
    });

    test('route tanpa #[UsesListing] menghasilkan pelanggaran dengan pesan jelas', function () {
        $route = RouteFacade::getRoutes()->getByName('admin.__kontrak_buruk.index');

        expect(ListingContract::violations($route, catalogKeys()))
            ->toHaveCount(1)
            ->and(ListingContract::violations($route, catalogKeys())[0])
            ->toContain('Route admin.__kontrak_buruk.index belum memakai standar listing (lihat 07 §3)');
    });

    test('route patuh tetapi kunci belum di katalog tetap ditolak', function () {
        $route = RouteFacade::getRoutes()->getByName('admin.__kontrak_patuh.index');

        expect(ListingContract::violations($route, catalogKeys())[0] ?? '')
            ->toContain('kunci [test_users] belum ada di tests/Fixtures/listing_catalog.php');
    });

    test('route patuh dengan kunci terdaftar lolos semua pemeriksaan termasuk uji HTTP', function () {
        $route = RouteFacade::getRoutes()->getByName('admin.__kontrak_patuh.index');
        User::factory()->create(['name' => 'Agus']);

        expect(ListingContract::violations($route, [...catalogKeys(), 'test_users']))->toBe([]);

        assertListingRouteBehaves($this, $route);
    });

    test('kontrak ikut menangkap route uji tidak patuh saat menelusuri semua route', function () {
        $names = ListingContract::routes()->map(fn (Route $r) => $r->getName())->all();

        expect($names)->toContain('admin.__kontrak_buruk.index', 'admin.__kontrak_patuh.index');
    });
});
