<?php

namespace App\Domain\Employee\Import;

/**
 * Menemukan baris judul kolom (BR-054, keputusan Mas 15 Nov 2026): di 10 baris teratas, baris
 * dengan judul yang paling banyak dikenali sinonim (minimal 2). Excel kantor sering memuat judul
 * laporan ("DAFTAR PEGAWAI …") di atas tabel, jadi "baris tidak kosong pertama" saja keliru. Bila
 * tidak ada baris yang cocok, dipakai baris tidak kosong pertama.
 */
final class HeaderDetector
{
    public const SCAN_ROWS = 10;

    public const MIN_MATCHES = 2;

    /**
     * @param  array<int, list<mixed>>  $rows  baris teratas berkas, nomor baris asli => sel
     * @return array{row: int, headers: list<string>}|null
     */
    public static function detect(array $rows): ?array
    {
        $best = null;
        $bestScore = 0;
        $firstNonEmpty = null;

        foreach ($rows as $number => $cells) {
            if ($number > self::SCAN_ROWS) {
                break;
            }
            if (EmployeeImportReader::isEmptyRow($cells)) {
                continue;
            }
            $firstNonEmpty ??= $number;

            $score = count(array_unique(array_filter(array_map(
                fn (mixed $cell) => EmployeeImportFields::fieldForHeader($cell),
                $cells,
            ))));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$number, $score];
            }
        }

        $row = $bestScore >= self::MIN_MATCHES ? $best : $firstNonEmpty;

        return $row === null ? null : ['row' => $row, 'headers' => self::headers($rows[$row])];
    }

    /**
     * Judul kolom sebagai teks; kolom kosong di ujung kanan dibuang.
     *
     * @param  list<mixed>  $cells
     * @return list<string>
     */
    private static function headers(array $cells): array
    {
        $headers = array_map(fn (mixed $cell) => RowNormalizer::text($cell) ?? '', $cells);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }

        return $headers;
    }
}
