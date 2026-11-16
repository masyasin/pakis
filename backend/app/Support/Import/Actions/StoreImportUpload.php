<?php

namespace App\Support\Import\Actions;

use App\Models\User;
use App\Support\Import\HeaderDetector;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportFile;
use App\Support\Import\ImportRegistry;
use App\Support\Import\ImportStatus;
use App\Support\Import\ImportType;
use App\Support\Import\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

/**
 * Langkah 1 impor (BR-054, BR-170, SEC-050): periksa ISI berkas (bukan hanya ekstensi), simpan di
 * disk privat dengan nama acak, temukan baris judul (sinonim field tipe impor), hitung baris data,
 * lalu buat batch `uploaded` bertipe itu.
 */
final class StoreImportUpload
{
    public function handle(User $actor, ImportType $type, UploadedFile $file): ImportBatch
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
            [$detected, $dataRows] = $this->scan($batch, $type);

            if ($detected === null) {
                $fail('Baris judul kolom tidak ditemukan di 10 baris teratas. Gunakan templat atau periksa berkasnya.');
            }
            $max = ImportRegistry::maxRows($type->key());
            if ($dataRows > $max) {
                $fail("Berkas berisi lebih dari {$max} baris data. Bagi menjadi beberapa berkas.");
            }
            if ($dataRows === 0) {
                $fail('Berkas tidak berisi baris data di bawah judul kolom.');
            }

            $batch->forceFill([
                'type' => $type->key(),
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
    private function scan(ImportBatch $batch, ImportType $type): array
    {
        $top = [];
        $detected = null;
        $count = 0;
        $limit = ImportRegistry::maxRows($type->key());

        foreach ((new SpreadsheetReader)->rows(ImportFile::path($batch), ImportFile::extension($batch)) as $number => $cells) {
            if ($detected === null) {
                if ($number <= HeaderDetector::SCAN_ROWS) {
                    $top[$number] = $cells;

                    continue;
                }
                $detected = HeaderDetector::detect($top, $type->fields()) ?? ['row' => PHP_INT_MAX, 'headers' => []];
                if ($detected['row'] === PHP_INT_MAX) {
                    return [null, 0];
                }
                $count = self::countAfter($top, $detected['row']);
            }
            if (! SpreadsheetReader::isEmptyRow($cells)) {
                $count++;
            }
            // Berhenti membaca begitu melewati batas: tidak perlu menelusuri berkas raksasa sampai habis.
            if ($count > $limit) {
                break;
            }
        }

        if ($detected === null) {
            $detected = HeaderDetector::detect($top, $type->fields());
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
            if ($number > $headerRow && ! SpreadsheetReader::isEmptyRow($cells)) {
                $count++;
            }
        }

        return $count;
    }
}
