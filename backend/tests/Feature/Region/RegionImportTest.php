<?php

use App\Domain\Region\Models\District;
use App\Domain\Region\Models\Province;
use App\Domain\Region\Models\Regency;
use App\Domain\Region\Models\Village;
use Illuminate\Support\Facades\Schema;

/*
 * FR-021 / OQ-003: `regions:import` idempoten dari dump SQL Sintech.
 */

const KUDUS_FIXTURE = 'tests/Fixtures/regions_kudus.sql';

function regionCounts(): array
{
    return [Province::query()->count(), Regency::query()->count(), District::query()->count(), Village::query()->count()];
}

function tempSql(string $sql): string
{
    $path = tempnam(sys_get_temp_dir(), 'wilayah');
    file_put_contents($path, $sql);

    return $path;
}

test('impor fixture Kudus: kode bertitik, induk benar, berkas asli urutan kab/kota sebelum provinsi', function () {
    $this->artisan('regions:import', ['path' => base_path(KUDUS_FIXTURE)])
        ->expectsOutputToContain('Impor wilayah selesai')
        ->assertSuccessful();

    expect(regionCounts())->toBe([1, 2, 10, 43])
        ->and(Village::query()->find('33.19.04.2003'))
        ->name->toBe('Kalirejo')
        ->district_code->toBe('33.19.04')
        ->and(District::query()->find('33.19.04')->regency_code)->toBe('33.19')
        ->and(Regency::query()->find('33.19')->name)->toBe('Kabupaten Kudus');
});

test('impor 2× tidak menggandakan baris, nama ikut sumber terbaru', function () {
    $this->artisan('regions:import', ['path' => base_path(KUDUS_FIXTURE)])->assertSuccessful();
    $before = regionCounts();

    $changed = tempSql(str_replace("'Kalirejo'", "'Kalirejo Baru'", (string) file_get_contents(base_path(KUDUS_FIXTURE))));
    $this->artisan('regions:import', ['path' => $changed])->assertSuccessful();
    unlink($changed);

    expect(regionCounts())->toBe($before)
        ->and(Village::query()->find('33.19.04.2003')->name)->toBe('Kalirejo Baru');
});

test('baris yatim, kode tak cocok induk, dan nama kosong dilewati & dilaporkan', function () {
    $path = tempSql(implode("\n", [
        "INSERT INTO `regionprovince` VALUES ('33', 'Jawa Tengah');",
        "INSERT INTO `regioncity` VALUES ('3319', '33', 'Kabupaten Kudus');",
        "INSERT INTO `regiondistrict` VALUES ('331904', '3319', 'Undaan');",
        "INSERT INTO `regionvillage` VALUES ('3319042001', '331904', 'Wonosoco', '59372');",
        "INSERT INTO `regionvillage` VALUES ('3319052001', '331905', 'Induk tidak ada', '59372');",
        "INSERT INTO `regionvillage` VALUES ('3320012001', '331904', 'Kode beda induk', '59372');",
        "INSERT INTO `regionvillage` VALUES ('3319042002', '331904', '   ', '59372');",
        "INSERT INTO `regionvillage` VALUES ('33190420', '331904', 'Kode pendek', '59372');",
    ]));

    $this->artisan('regions:import', ['path' => $path])
        ->expectsOutputToContain('4 baris dilewati')
        ->expectsOutputToContain('induk 33.19.05 tidak ditemukan')
        ->assertSuccessful();
    unlink($path);

    expect(Village::query()->pluck('code')->all())->toBe(['33.19.04.2001']);
});

test('isi berkas tidak pernah dieksekusi sebagai SQL', function () {
    $path = tempSql("DROP TABLE users;\nINSERT INTO `regionprovince` VALUES ('33', 'Jawa Tengah'); DROP TABLE users;\nINSERT INTO `regionprovince` VALUES ('34', 'DI Yogyakarta');");

    $this->artisan('regions:import', ['path' => $path])->assertSuccessful();
    unlink($path);

    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Province::query()->pluck('code')->all())->toBe(['34']);
});

test('berkas tidak ada atau format tak dikenal → gagal tanpa mengubah data', function () {
    $this->artisan('regions:import', ['path' => base_path('tests/Fixtures/tidak-ada.sql')])
        ->expectsOutputToContain('Berkas tidak ditemukan')
        ->assertFailed();

    $this->artisan('regions:import', ['path' => base_path(KUDUS_FIXTURE), '--format' => 'bps'])
        ->expectsOutputToContain('Format tidak dikenal')
        ->assertFailed();

    expect(regionCounts())->toBe([0, 0, 0, 0]);
});
