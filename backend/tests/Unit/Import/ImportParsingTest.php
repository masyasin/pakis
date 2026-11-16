<?php

use App\Domain\Employee\Enums\Gender;
use App\Domain\Employee\Import\EmployeeImportFields;
use App\Domain\Employee\Import\RowNormalizer;
use App\Support\Import\HeaderDetector;
use App\Support\Import\MappingSuggester;

test('BR-054 tanggal: dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd, nama bulan, sel tanggal, serial Excel', function (mixed $input, string $expected) {
    expect(RowNormalizer::date($input))->toBe($expected);
})->with([
    ['03/11/2026', '2026-11-03'],
    ['3-11-2026', '2026-11-03'],
    ['2026-11-03', '2026-11-03'],
    ['2026-11-03 00:00:00', '2026-11-03'],
    ['3 November 2026', '2026-11-03'],
    ['3 Nov 2026', '2026-11-03'],
    ['17 Agustus 1995', '1995-08-17'],
    ['17 Agt 1995', '1995-08-17'],
    ['1 Mei 2020', '2020-05-01'],
    ['31 Des 2024', '2024-12-31'],
    ['5 Okt 2021', '2021-10-05'],
    [new DateTimeImmutable('2024-02-29'), '2024-02-29'],
    [45658, '2025-01-01'],
    [45658.75, '2025-01-01'],
    ['45658', '2025-01-01'],
]);

test('BR-054 tanggal tidak dikenali / tidak ada di kalender → galat', function (mixed $input) {
    RowNormalizer::date($input);
})->with(['31/02/2024', 'besok', '2024/13/01', '12-2024', '03/11/26'])->throws(InvalidArgumentException::class);

test('BR-054 jenis kelamin: L/P, Laki-laki/Perempuan, Pria/Wanita', function (string $input, Gender $expected) {
    expect(RowNormalizer::gender($input))->toBe($expected);
})->with([
    ['L', Gender::Male], ['l', Gender::Male], ['Laki-laki', Gender::Male], ['LAKI LAKI', Gender::Male], ['Pria', Gender::Male],
    ['P', Gender::Female], ['Perempuan', Gender::Female], ['wanita', Gender::Female],
]);

test('BR-054 HP 0812-xxx dan angka tanpa 0 di depan (sel angka Excel) → 62…', function (mixed $input, string $expected) {
    expect(RowNormalizer::phone($input))->toBe($expected);
})->with([
    ['0812-3456-7890', '6281234567890'],
    [81234567890, '6281234567890'],
    ["'081234567890", '6281234567890'],
]);

test('SEC-010 NIK sel angka ditolak (Excel membulatkan 16 digit); teks 16 digit diterima', function () {
    expect(RowNormalizer::nik('3319 0101 0101 0001'))->toBe('3319010101010001')
        ->and(fn () => RowNormalizer::nik(3319010101010001))->toThrow(InvalidArgumentException::class, 'sebagai angka')
        ->and(fn () => RowNormalizer::nik('331901010101'))->toThrow(InvalidArgumentException::class, '(sekarang 12)');
});

test('BR-054 kode: sel angka 1001.0 → "1001", apostrof Excel dibuang', function () {
    expect(RowNormalizer::code(1001.0))->toBe('1001')
        ->and(RowNormalizer::code("'PJA-0001"))->toBe('PJA-0001')
        ->and(RowNormalizer::code('  '))->toBeNull();
});

test('BR-054 sinonim judul kolom (huruf besar, tanda baca, isi kurung diabaikan)', function (string $header, ?string $field) {
    expect(EmployeeImportFields::fieldForHeader($header))->toBe($field);
})->with([
    ['Nama', 'full_name'], ['Nama Lengkap', 'full_name'], ['NAMA PEGAWAI', 'full_name'],
    ['No HP', 'phone'], ['WA', 'phone'], ['Telepon', 'phone'], ['No. HP (WA)', 'phone'],
    ['Jenis Kelamin (L/P)*', 'gender'], ['Tgl. Lahir', 'birth_date'], ['NIK', 'nik'],
    ['Keterangan', null],
]);

test('BR-054 header cerdas: judul laporan di baris 1, header di baris 3', function () {
    $rows = [
        1 => ['DAFTAR PEGAWAI CV CONTOH'],
        2 => [null, null],
        3 => ['No', 'Nama Lengkap', 'L/P', 'Tgl Masuk', 'Catatan', null],
        4 => [1, 'Budi', 'L', '01/02/2024', 'x'],
    ];

    expect(HeaderDetector::detect($rows, EmployeeImportFields::definition()))->toBe(['row' => 3, 'headers' => ['No', 'Nama Lengkap', 'L/P', 'Tgl Masuk', 'Catatan']]);
});

test('BR-054 header: tanpa judul yang dikenali → baris tidak kosong pertama; berkas kosong → null', function () {
    expect(HeaderDetector::detect([1 => [null], 2 => ['Kolom A', 'Kolom B'], 3 => ['x', 'y']], EmployeeImportFields::definition()))->toBe(['row' => 2, 'headers' => ['Kolom A', 'Kolom B']])
        ->and(HeaderDetector::detect([1 => [null], 2 => ['  ']], EmployeeImportFields::definition()))->toBeNull();
});

test('BR-054 tanda tangan header: beda huruf besar/spasi sama, beda urutan berbeda', function () {
    expect(MappingSuggester::signature(['Nama Lengkap', 'No. HP']))->toBe(MappingSuggester::signature(['NAMA  LENGKAP', 'no hp']))
        ->and(MappingSuggester::signature(['Nama Lengkap', 'No. HP']))->not->toBe(MappingSuggester::signature(['No. HP', 'Nama Lengkap']));
});
