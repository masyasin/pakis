<?php

use App\Domain\Region\Models\Village;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FR-021: daftar desa/kelurahan dengan filter berjenjang (07 §3.4 `regions`) dan lookup `regions`.
 */

beforeEach(function () {
    $this->artisan('regions:import', ['path' => base_path('tests/Fixtures/regions_kudus.sql')])->assertSuccessful();
    $this->user = User::factory()->create();
});

function grantRegions(): void
{
    Gate::define('regions.view', fn () => true);
}

test('tanpa regions.view halaman wilayah 403 (deny-by-default sampai T-018)', function () {
    $this->actingAs($this->user)->get('/wilayah')->assertForbidden();
});

test('daftar desa lengkap dengan kecamatan, kab/kota, provinsi; default urut kode', function () {
    grantRegions();

    $this->actingAs($this->user)->get('/wilayah')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('regions/index')
            ->where('listing.pagination.total', 43)
            ->where('rows.0', [
                'code' => '33.19.02.1001',
                'name' => ($first = Village::query()->orderBy('code')->first())->name,
                'district' => 'Kota Kudus',
                'regency' => 'Kabupaten Kudus',
                'province' => 'Jawa Tengah',
            ])
            ->where('listing.state.sort', 'code'));
});

test('filter berjenjang memakai awalan kode: provinsi → kab/kota → kecamatan', function (array $filter, int $total) {
    grantRegions();

    $this->actingAs($this->user)->get('/wilayah?'.http_build_query(['filter' => $filter]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.pagination.total', $total)
            ->where('listing.state.invalid_filters', []));
})->with([
    'provinsi' => [['province' => '33'], 43],
    'kab/kota' => [['province' => '33', 'regency' => '33.20'], 2],
    'kecamatan' => [['province' => '33', 'regency' => '33.19', 'district' => '33.19.04'], 16],
]);

test('kode filter yang tidak ada atau tidak sah dilaporkan dan diabaikan', function (string $code) {
    grantRegions();

    $this->actingAs($this->user)->get('/wilayah?filter[regency]='.urlencode($code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.pagination.total', 43)
            ->has('listing.state.invalid_filters.regency'));
})->with(['33.99', "33.19%' OR 1=1 --", '3319']);

test('cari nama desa atau kode desa, sort nama', function () {
    grantRegions();

    $this->actingAs($this->user)->get('/wilayah?q=kalirejo')
        ->assertInertia(fn (Assert $page) => $page->where('listing.pagination.total', 1)->where('rows.0.code', '33.19.04.2003'));

    $this->actingAs($this->user)->get('/wilayah?q=33.20.01')
        ->assertInertia(fn (Assert $page) => $page->where('listing.pagination.total', 2));

    $this->actingAs($this->user)->get('/wilayah?sort=name')
        ->assertInertia(fn (Assert $page) => $page->where('listing.state.sort', 'name'));
});

test('spec filter berjenjang: kab/kota bergantung provinsi, kecamatan bergantung kab/kota', function () {
    grantRegions();

    $this->actingAs($this->user)->get('/wilayah')
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.spec.filters.0.key', 'province')
            ->where('listing.spec.filters.0.options_source', 'regions')
            ->where('listing.spec.filters.0.options_params', ['level' => 'province'])
            ->where('listing.spec.filters.1.depends_on', 'province')
            ->where('listing.spec.filters.2.key', 'district')
            ->where('listing.spec.filters.2.depends_on', 'regency')
            ->where('listing.spec.filters.2.options_params', ['level' => 'district']));
});

// --- Lookup `regions` -------------------------------------------------------------------------

test('lookup regions: level wajib, parent menyaring, kode string sebagai value', function () {
    $this->actingAs($this->user)->getJson('/lookup/regions')
        ->assertUnprocessable()->assertJsonValidationErrors('level');

    $this->actingAs($this->user)->getJson('/lookup/regions?level=regency&parent=33')
        ->assertOk()
        ->assertJsonPath('*.value', ['33.20', '33.19']) // urut nama: Jepara, Kudus
        ->assertJsonPath('*.label', ['Kabupaten Jepara', 'Kabupaten Kudus']);

    $this->actingAs($this->user)->getJson('/lookup/regions?level=district&parent=33.19&q=und')
        ->assertExactJson([['value' => '33.19.04', 'label' => 'Undaan', 'hint' => '33.19.04']]);

    $this->actingAs($this->user)->getJson('/lookup/regions?level=village&parent=33.19.04')
        ->assertOk()->assertJsonCount(16);
});

test('lookup regions: ids[] kode string untuk label chip, kode salah 422', function () {
    $this->actingAs($this->user)->getJson('/lookup/regions?level=district&ids[]=33.19.04')
        ->assertExactJson([['value' => '33.19.04', 'label' => 'Undaan', 'hint' => '33.19.04']]);

    $this->actingAs($this->user)->getJson('/lookup/regions?level=district&ids[]=1%3D1')
        ->assertUnprocessable()->assertJsonValidationErrors('ids.0');

    $this->actingAs($this->user)->getJson('/lookup/regions?level=kota')
        ->assertUnprocessable()->assertJsonValidationErrors('level');
});

test('lookup regions maksimal 20 walau tanpa pencarian', function () {
    $this->actingAs($this->user)->getJson('/lookup/regions?level=village')
        ->assertOk()->assertJsonCount(20);
});
