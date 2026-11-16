<?php

namespace App\Support\Import;

use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Pembaca berkas impor (BR-054, SEC-050). XLSX lewat OpenSpout (streaming, sheet pertama, tanpa
 * makro); CSV lewat `fgetcsv` dengan deteksi pemisah & encoding. Hasilnya nilai PHP asli —
 * string, int/float (sel angka), DateTimeInterface (sel tanggal), bool, atau null — supaya
 * normalisasi bisa membedakan "angka" dari "teks" (penting untuk NIK 16 digit).
 *
 * Sel formula SELALU dibaca nilai tersimpannya; formula tidak pernah dievaluasi.
 */
final class SpreadsheetReader
{
    /**
     * @return Generator<int, list<mixed>> nomor baris asli (1 = baris pertama berkas) => sel
     */
    public function rows(string $path, string $extension): Generator
    {
        return strtolower($extension) === 'csv' ? $this->csv($path) : $this->xlsx($path);
    }

    /** @param  array<int, mixed>  $cells */
    public static function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof DateTimeInterface || is_int($cell) || is_float($cell) || is_bool($cell)) {
                return false;
            }
            if (is_string($cell) && trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return Generator<int, list<mixed>> */
    private function xlsx(string $path): Generator
    {
        // Baris kosong dipertahankan agar kunci iterator = nomor baris asli di Excel.
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);

        // Iterator disimpan di variabel (bukan sementara di foreach) agar bisa dilepas di `finally`.
        $sheets = $reader->getSheetIterator();
        $sheets->rewind();
        $sheet = $sheets->valid() ? $sheets->current() : null;
        $rows = $sheet?->getRowIterator();

        try {
            if ($rows !== null) {
                foreach ($rows as $index => $row) {
                    // `cells` dikunci indeks kolom dan bisa berlubang: isi null agar posisi kolom tetap.
                    $max = $row->cells === [] ? -1 : max(array_keys($row->cells));
                    $cells = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $cell = $row->cells[$i] ?? null;
                        $cells[] = match (true) {
                            $cell === null => null,
                            $cell instanceof FormulaCell => $cell->getComputedValue(),
                            default => $cell->getValue(),
                        };
                    }
                    yield (int) $index => $cells; // hanya sheet pertama
                }
            }
        } finally {
            $reader->close();
            // Objek sheet/baris OpenSpout saling mereferensi dan menahan XMLReader: tanpa ini berkas
            // tetap terkunci (Windows) sampai proses selesai — masalah di queue worker yang hidup lama.
            unset($rows, $sheet, $sheets, $row, $reader);
            gc_collect_cycles();
        }
    }

    /** @return Generator<int, list<mixed>> */
    private function csv(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Berkas tidak bisa dibuka.');
        }

        try {
            $sample = (string) fread($handle, 8192);
            rewind($handle);
            $delimiter = self::detectDelimiter($sample);
            $utf8 = mb_check_encoding($sample, 'UTF-8') || mb_check_encoding(mb_strcut($sample, 0, max(0, strlen($sample) - 3), 'UTF-8'), 'UTF-8');

            $number = 0;
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                $row = [];
                foreach ($cells as $i => $cell) {
                    $value = (string) $cell;
                    if ($number === 1 && $i === 0) {
                        $value = (string) preg_replace('/^\xEF\xBB\xBF/', '', $value); // BOM Excel
                    }
                    // Excel Windows menyimpan CSV dalam Windows-1252 bila tidak dipilih "UTF-8".
                    $row[] = $utf8 ? $value : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
                }
                yield $number => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Pemisah paling sering di cuplikan awal (bukan hanya baris pertama, yang bisa berupa judul):
     * `;` umum di Excel berlokal Indonesia.
     */
    private static function detectDelimiter(string $sample): string
    {
        $counts = [',' => substr_count($sample, ','), ';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
