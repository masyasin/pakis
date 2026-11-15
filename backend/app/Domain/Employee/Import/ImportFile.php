<?php

namespace App\Domain\Employee\Import;

use App\Domain\Employee\Models\ImportBatch;
use Generator;
use Illuminate\Support\Facades\Storage;

/**
 * Akses berkas milik batch di disk privat (SEC-050): baris data setelah baris judul, dengan nomor
 * baris asli, dan sel yang sudah dipetakan ke field sistem.
 */
final class ImportFile
{
    public const DISK = 'local';

    public const DIRECTORY = 'imports';

    public static function path(ImportBatch $batch): string
    {
        return Storage::disk(self::DISK)->path($batch->stored_path);
    }

    public static function extension(ImportBatch $batch): string
    {
        return strtolower(pathinfo($batch->stored_path, PATHINFO_EXTENSION));
    }

    /**
     * Baris data (setelah judul, baris kosong dilewati).
     *
     * @return Generator<int, list<mixed>>
     */
    public static function dataRows(ImportBatch $batch): Generator
    {
        $header = $batch->headerRow();
        foreach ((new EmployeeImportReader)->rows(self::path($batch), self::extension($batch)) as $number => $cells) {
            if ($number <= $header || EmployeeImportReader::isEmptyRow($cells)) {
                continue;
            }
            yield $number => $cells;
        }
    }

    /**
     * @param  list<mixed>  $cells
     * @param  array<int, string>  $columns  indeks kolom → field
     * @return array<string, mixed>
     */
    public static function mapRow(array $cells, array $columns): array
    {
        $raw = [];
        foreach ($columns as $index => $field) {
            $raw[$field] = $cells[$index] ?? null;
        }

        return $raw;
    }
}
