<?php

use App\Domain\Region\Enums\RegionLevel;
use App\Domain\Region\Import\SintechRegionParser;

/*
 * Parser dump SQL Sintech (OQ-003). Berkas hanya DIBACA sebagai teks, tidak pernah dieksekusi.
 */

function parseSql(string $sql): array
{
    $path = tempnam(sys_get_temp_dir(), 'wilayah');
    file_put_contents($path, $sql);

    try {
        $rows = [];
        foreach ((new SintechRegionParser)->parse($path) as $row) {
            $rows[] = $row;
        }

        return $rows;
    } finally {
        unlink($path);
    }
}

test('membaca keempat tabel, CRLF, dan mengabaikan postalCode serta baris lain', function () {
    $rows = parseSql(implode("\r\n", [
        '/* Navicat */',
        'SET NAMES utf8mb4;',
        'CREATE TABLE `regioncity` (`id` varchar(191));',
        "INSERT INTO `regioncity` VALUES ('3319', '33', 'Kabupaten Kudus');",
        "INSERT INTO `regionprovince` VALUES ('33', 'Jawa Tengah');",
        "INSERT INTO `regiondistrict` VALUES ('331904', '3319', 'Undaan');",
        "INSERT INTO `regionvillage` VALUES ('3319042003', '331904', 'Kalirejo', '59372');",
        "INSERT INTO `lainnya` VALUES ('1', 'abaikan');",
        '',
    ]));

    expect($rows)->toBe([
        ['level' => RegionLevel::Regency, 'code' => '3319', 'parent' => '33', 'name' => 'Kabupaten Kudus', 'line' => 4],
        ['level' => RegionLevel::Province, 'code' => '33', 'parent' => null, 'name' => 'Jawa Tengah', 'line' => 5],
        ['level' => RegionLevel::District, 'code' => '331904', 'parent' => '3319', 'name' => 'Undaan', 'line' => 6],
        ['level' => RegionLevel::Village, 'code' => '3319042003', 'parent' => '331904', 'name' => 'Kalirejo', 'line' => 7],
    ]);
});

test('kutip di dalam nama (\\\' dan \'\') dan NULL dibaca benar', function () {
    $rows = parseSql(implode("\n", [
        "INSERT INTO `regionvillage` VALUES ('1101012001', '110101', 'Pa\\'nan', NULL);",
        "INSERT INTO `regionvillage` VALUES ('1101012002', '110101', 'Ma''rang', '23773');",
        "INSERT INTO `regionvillage` VALUES ('1101012003', '110101', NULL, '23773');",
    ]));

    expect(array_column($rows, 'name'))->toBe(["Pa'nan", "Ma'rang", null]);
});

test('baris INSERT rusak dilaporkan sebagai galat, bukan dieksekusi', function () {
    $rows = parseSql("INSERT INTO `regionvillage` VALUES ('1101012001', '110101', 'Tanpa penutup);\nDROP TABLE users;");

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['error'] ?? null)->toBe('Baris INSERT tidak bisa dibaca.');
});
