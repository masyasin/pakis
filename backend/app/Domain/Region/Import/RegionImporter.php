<?php

namespace App\Domain\Region\Import;

use App\Domain\Region\Enums\RegionLevel;
use Illuminate\Support\Facades\DB;

/**
 * Impor master wilayah idempoten (FR-021, OQ-003): upsert per kode, dari provinsi ke desa.
 *
 * Berkas Sintech mengurutkan tabel kab/kota sebelum provinsi, jadi semua baris dikumpulkan dulu
 * per level lalu disimpan berurutan agar FK induk selalu terpenuhi. Baris yang tidak sah dilewati
 * dan dilaporkan; data yang sudah ada tidak dihapus (bisa dirujuk lokasi/pegawai).
 */
final class RegionImporter
{
    private const CHUNK = 1000;

    private const MAX_NAME = 100;

    /** Contoh baris dilewati yang disimpan di laporan. */
    private const MAX_SAMPLES = 20;

    /**
     * @param  iterable<array{level: RegionLevel, code: string|null, parent: string|null, name: string|null, line: int}|array{error: string, line: int}>  $rows
     * @return array{counts: array<string, int>, skipped: int, samples: list<string>}
     */
    public function import(iterable $rows): array
    {
        /** @var array<string, array<string, array<string, string>>> $byLevel level => code => baris */
        $byLevel = array_fill_keys(array_map(fn (RegionLevel $l) => $l->value, RegionLevel::cases()), []);
        $skipped = 0;
        $samples = [];
        $skip = function (int $line, string $reason) use (&$skipped, &$samples): void {
            $skipped++;
            if (count($samples) < self::MAX_SAMPLES) {
                $samples[] = "baris {$line}: {$reason}";
            }
        };

        // 1. Validasi bentuk tiap baris (tanpa melihat DB).
        /** @var array<string, array<string, int>> $lines level => code => nomor baris (untuk laporan) */
        $lines = array_fill_keys(array_keys($byLevel), []);
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $skip($row['line'], $row['error']);

                continue;
            }

            $level = $row['level'];
            $code = RegionCode::normalize($row['code']);
            if ($code === null || RegionCode::level($code) !== $level) {
                $skip($row['line'], "kode \"{$row['code']}\" bukan kode {$level->label()} yang sah");

                continue;
            }

            $name = trim((string) $row['name']);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                $skip($row['line'], "nama {$level->label()} {$code} kosong atau lebih dari ".self::MAX_NAME.' karakter');

                continue;
            }

            $record = ['code' => $code, 'name' => $name];
            if ($column = $level->parentColumn()) {
                $parent = RegionCode::normalize($row['parent']);
                if ($parent === null || $parent !== RegionCode::parent($code)) {
                    $skip($row['line'], "kode {$code} bukan bagian dari induk \"{$row['parent']}\"");

                    continue;
                }
                $record[$column] = $parent;
            }

            // Kode dobel di berkas: baris terakhir yang dipakai.
            $byLevel[$level->value][$code] = $record;
            $lines[$level->value][$code] = $row['line'];
        }

        // 2. Simpan dari atas ke bawah; induk harus ada di DB atau di berkas ini.
        $counts = [];
        foreach (RegionLevel::cases() as $level) {
            $records = $byLevel[$level->value];

            if ($parentLevel = $level->parent()) {
                $known = array_flip(DB::table($parentLevel->table())->pluck('code')->all());
                $column = (string) $level->parentColumn();
                foreach ($records as $code => $record) {
                    if (! isset($known[$record[$column]])) {
                        $skip($lines[$level->value][$code] ?? 0, "induk {$record[$column]} tidak ditemukan untuk {$level->label()} {$code}");
                        unset($records[$code]);
                    }
                }
            }

            DB::transaction(function () use ($level, $records) {
                $update = $level->parentColumn() ? ['name', $level->parentColumn()] : ['name'];
                foreach (array_chunk(array_values($records), self::CHUNK) as $chunk) {
                    DB::table($level->table())->upsert($chunk, ['code'], $update);
                }
            });

            $counts[$level->value] = count($records);
        }

        return ['counts' => $counts, 'skipped' => $skipped, 'samples' => $samples];
    }
}
