<?php

/*
 * Helper bersama test impor pegawai (T-027, T-028). Fungsi global Pest harus unik, jadi
 * didefinisikan sekali di sini dan dimuat dengan require_once oleh tiap berkas test impor.
 */

use App\Models\User;
use App\Support\Import\ImportBatch;
use App\Support\Import\ImportRegistry;
use App\Support\Import\MappingSuggester;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** @param  list<list<mixed>>  $rows */
function impXlsx(array $rows, string $name = 'pegawai.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function impCsv(string $content, string $name = 'pegawai.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/** Unggah lalu kembalikan batch terakhir. */
function impUpload(User $actor, UploadedFile $file): ImportBatch
{
    test()->actingAs($actor)->post('/impor/pegawai', ['file' => $file])->assertSessionHasNoErrors();

    return ImportBatch::query()->latest('id')->firstOrFail();
}

/** Simpan pemetaan hasil saran apa adanya. */
function impMapSuggested(User $actor, ImportBatch $batch, array $extra = []): void
{
    $columns = MappingSuggester::suggest(ImportRegistry::forBatch($batch), $batch->headers())['columns'];
    test()->actingAs($actor)->put("/impor/{$batch->id}/pemetaan", ['columns' => $columns, ...$extra])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/impor/{$batch->id}/baris");
}

/** Excel "berantakan" (data dummy): judul + baris kosong, header di baris 3, kolom ekstra, format campur. */
function impMessy(string $nik): UploadedFile
{
    $file = impXlsx([
        ['DAFTAR PEGAWAI CV CONTOH (DATA DUMMY)'],
        [''],
        ['No', 'Nama Lengkap', 'JK', 'Tgl Masuk', 'Status', 'Lokasi', 'Jabatan', 'No HP', 'NIK', 'Tanggal Lahir', 'Keterangan'],
        [1, 'Andi  Pratama', 'L', '01/02/2024', 'Tetap', 'GD-01', 'STF', '0812-3456-7890', $nik, '17 Agustus 1995', 'pindahan'],
        [2, 'Sari Wulandari', 'Perempuan', new DateTimeImmutable('2023-05-10'), 'permanent', 'Toko Uji', 'Staf Gudang', 81234567891, '', 34000, ''],
        [3, 'Budi Santoso', 'Pria', '2024-13-01', 'Kontrak Lepas', 'XX-99', 'STF', 'abc', 3319010101010001, '', ''],
        [''],
        [4, 'Andi Pratama Dobel', 'L', '3 Nov 2025', 'Tetap', 'GD-01', 'STF', '', $nik, '', ''],
        [5, '=1+1', 'P', '2025-01-02', 'Tetap', 'GD-01', 'STF', '', '', '', ''],
    ]);

    // Excel menyimpan hasil formula di <v>; writer OpenSpout tidak. Sisipkan nilai tersimpan agar
    // test membuktikan yang dibaca adalah nilai itu, bukan hasil evaluasi formula (BR-054).
    $zip = new ZipArchive;
    $zip->open($file->getRealPath());
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $patched = (string) preg_replace('/<c r="B9"[^>]*><f>1\+1<\/f><\/c>/', '<c r="B9" t="str"><f>1+1</f><v>Rina Tersimpan</v></c>', $sheet, 1, $count);
    expect($count)->toBe(1);
    $zip->addFromString('xl/worksheets/sheet1.xml', $patched);
    $zip->close();

    return $file;
}
