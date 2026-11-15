<?php

namespace App\Domain\Employee\Actions;

use App\Domain\Employee\Enums\ImportStatus;
use App\Domain\Employee\Import\EmployeeImportReader;
use App\Domain\Employee\Import\HeaderDetector;
use App\Domain\Employee\Import\ImportFile;
use App\Domain\Employee\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Langkah 1 impor (BR-054, SEC-050): periksa ISI berkas (bukan hanya ekstensi), simpan di disk
 * privat dengan nama acak, temukan baris judul, hitung baris data, lalu buat batch `uploaded`.
 */
final class StoreImportUpload
{
    public function handle(User $actor, UploadedFile $file): ImportBatch
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['file' => $message]);

        $extension = strtolower($file->getClientOriginalExtension());
        $realPath = (string) $file->getRealPath();
        if (! in_array($extension, ['xlsx', 'csv'], true) || ! self::contentMatches($realPath, $extension)) {
            $fail('Berkas harus Excel (.xlsx) atau CSV yang asli. Isi berkas tidak cocok dengan jenisnya.');
        }

        $path = Storage::disk(ImportFile::DISK)->putFileAs(ImportFile::DIRECTORY, $file, Str::random(40).'.'.$extension);
        if ($path === false) {
            $fail('Berkas gagal disimpan. Coba lagi.');
        }

        try {
            $batch = new ImportBatch;
            $batch->forceFill(['stored_path' => $path, 'mapping' => ['header_row' => 1]]);
            [$detected, $dataRows] = $this->scan($batch);

            if ($detected === null) {
                $fail('Baris judul kolom tidak ditemukan di 10 baris teratas. Gunakan templat atau periksa berkasnya.');
            }
            $max = (int) setting('import.max_rows');
            if ($dataRows > $max) {
                $fail("Berkas berisi lebih dari {$max} baris data. Bagi menjadi beberapa berkas.");
            }
            if ($dataRows === 0) {
                $fail('Berkas tidak berisi baris data di bawah judul kolom.');
            }

            $batch->forceFill([
                'type' => ImportBatch::TYPE_EMPLOYEES,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'file_hash' => hash_file('sha256', ImportFile::path($batch)),
                'status' => ImportStatus::Uploaded,
                'mapping' => ['header_row' => $detected['row'], 'headers' => $detected['headers'], 'columns' => null],
                'total_rows' => $dataRows,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            return $batch;
        } catch (Throwable $e) {
            Storage::disk(ImportFile::DISK)->delete($path);

            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);

            throw ValidationException::withMessages(['file' => 'Berkas tidak bisa dibaca. Pastikan berkas tidak rusak atau terkunci kata sandi.']);
        }
    }

    /**
     * Sniff isi: .xlsx harus arsip ZIP berstruktur workbook Office; .csv harus teks biasa tanpa byte
     * NUL dan tanpa tag PHP. Berkas `.php` yang diganti nama tertolak di sini.
     */
    public static function contentMatches(string $path, string $extension): bool
    {
        if ($extension === 'xlsx') {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return false;
            }
            $ok = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();

            return $ok;
        }

        $head = (string) file_get_contents($path, false, null, 0, 8192);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return $head !== ''
            && ! str_contains($head, "\0")
            && stripos($head, '<?php') === false
            && stripos($head, '<?=') === false
            && (str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/vnd.ms-excel'], true));
    }

    /**
     * @return array{0: array{row: int, headers: list<string>}|null, 1: int}
     */
    private function scan(ImportBatch $batch): array
    {
        $top = [];
        $detected = null;
        $count = 0;
        $limit = (int) setting('import.max_rows');

        foreach ((new EmployeeImportReader)->rows(ImportFile::path($batch), ImportFile::extension($batch)) as $number => $cells) {
            if ($detected === null) {
                if ($number <= HeaderDetector::SCAN_ROWS) {
                    $top[$number] = $cells;

                    continue;
                }
                $detected = HeaderDetector::detect($top) ?? ['row' => PHP_INT_MAX, 'headers' => []];
                if ($detected['row'] === PHP_INT_MAX) {
                    return [null, 0];
                }
                $count = self::countAfter($top, $detected['row']);
            }
            if (! EmployeeImportReader::isEmptyRow($cells)) {
                $count++;
            }
            // Berhenti membaca begitu melewati batas: tidak perlu menelusuri berkas raksasa sampai habis.
            if ($count > $limit) {
                break;
            }
        }

        if ($detected === null) {
            $detected = HeaderDetector::detect($top);
            if ($detected === null) {
                return [null, 0];
            }
            $count = self::countAfter($top, $detected['row']);
        }

        return [$detected, $count];
    }

    /** @param  array<int, list<mixed>>  $top */
    private static function countAfter(array $top, int $headerRow): int
    {
        $count = 0;
        foreach ($top as $number => $cells) {
            if ($number > $headerRow && ! EmployeeImportReader::isEmptyRow($cells)) {
                $count++;
            }
        }

        return $count;
    }
}
