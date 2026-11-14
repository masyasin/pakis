<?php

namespace App\Domain\Region\Import;

use App\Domain\Region\Enums\RegionLevel;
use Generator;
use RuntimeException;

/**
 * Pembaca dump SQL wilayah Sintech (OQ-003): tabel `regionprovince(id,name)`,
 * `regioncity(id,provinceId,name)`, `regiondistrict(id,cityId,name)`,
 * `regionvillage(id,districtId,name,postalCode)`, satu `INSERT … VALUES (…);` per baris.
 *
 * KEAMANAN: berkas hanya dibaca sebagai teks dan nilainya diurai sendiri. Isinya tidak pernah
 * dijalankan ke database, sehingga `DROP TABLE …` atau SQL lain di berkas tidak berpengaruh.
 */
final class SintechRegionParser
{
    private const TABLES = [
        'regionprovince' => RegionLevel::Province,
        'regioncity' => RegionLevel::Regency,
        'regiondistrict' => RegionLevel::District,
        'regionvillage' => RegionLevel::Village,
    ];

    /**
     * @return Generator<int, array{level: RegionLevel, code: string|null, parent: string|null, name: string|null, line: int}|array{error: string, line: int}>
     */
    public function parse(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Berkas tidak bisa dibuka: {$path}");
        }

        try {
            $number = 0;
            while (($line = fgets($handle)) !== false) {
                $number++;
                $line = rtrim($line, "\r\n");

                if (! preg_match('/^INSERT INTO `(region(?:province|city|district|village))` VALUES/', $line, $table)) {
                    continue; // komentar, CREATE TABLE, SET, tabel lain
                }

                $values = preg_match('/^INSERT INTO `\w+` VALUES \((.*)\);\s*$/', $line, $match) === 1
                    ? self::values($match[1])
                    : null;

                $level = self::TABLES[$table[1]];
                $isProvince = $level === RegionLevel::Province;

                if ($values === null || count($values) < ($isProvince ? 2 : 3)) {
                    yield ['error' => 'Baris INSERT tidak bisa dibaca.', 'line' => $number];

                    continue;
                }

                yield [
                    'level' => $level,
                    'code' => $values[0],
                    'parent' => $isProvince ? null : $values[1],
                    'name' => $isProvince ? $values[1] : $values[2],
                    'line' => $number,
                ];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Urai daftar nilai `'a', 'b', NULL, 123`. Mendukung `\'`, `\\`, dan `''` di dalam string.
     *
     * @return list<string|null>|null null bila tidak sah
     */
    public static function values(string $body): ?array
    {
        $values = [];
        $length = strlen($body);
        $i = 0;

        while (true) {
            while ($i < $length && $body[$i] === ' ') {
                $i++;
            }

            if ($i < $length && $body[$i] === "'") {
                $i++;
                $value = '';
                $closed = false;
                while ($i < $length) {
                    $char = $body[$i];
                    if ($char === '\\' && $i + 1 < $length) {
                        $value .= $body[$i + 1];
                        $i += 2;
                    } elseif ($char === "'" && ($body[$i + 1] ?? '') === "'") {
                        $value .= "'";
                        $i += 2;
                    } elseif ($char === "'") {
                        $i++;
                        $closed = true;
                        break;
                    } else {
                        $value .= $char;
                        $i++;
                    }
                }
                if (! $closed) {
                    return null;
                }
                $values[] = $value;
            } elseif (preg_match('/\G(NULL|-?\d+(?:\.\d+)?)/A', $body, $literal, 0, $i) === 1) {
                $values[] = $literal[1] === 'NULL' ? null : $literal[1];
                $i += strlen($literal[1]);
            } else {
                return null;
            }

            while ($i < $length && $body[$i] === ' ') {
                $i++;
            }

            if ($i >= $length) {
                return $values;
            }

            if ($body[$i] !== ',') {
                return null;
            }
            $i++;
        }
    }
}
