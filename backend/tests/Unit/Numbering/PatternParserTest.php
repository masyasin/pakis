<?php

use App\Domain\Numbering\Enums\ResetPeriod;
use App\Domain\Numbering\PatternParser;
use Carbon\CarbonImmutable;

/*
 * BR-025: validasi pola penomoran.
 */

test('BR-025 pola sah', function (string $pattern, ResetPeriod $reset) {
    expect(PatternParser::errors($pattern, $reset))->toBe([]);
})->with([
    'pegawai' => ['PJA-{YY}{SEQ:4}', ResetPeriod::Never],
    'koreksi bulanan' => ['KOR-{YYYY}{MM}-{SEQ:4}', ResetPeriod::Monthly],
    'harian' => ['SJ/{YY}{MM}{DD}/{SEQ:3}', ResetPeriod::Daily],
    'tahunan + lokasi' => ['INV.{LOC}.{YYYY}.{SEQ:5}', ResetPeriod::Yearly],
    'tanpa literal' => ['{SEQ:8}', ResetPeriod::Never],
]);

test('BR-025 pola monthly tanpa {MM} ditolak', function () {
    expect(PatternParser::errors('KOR-{YYYY}-{SEQ:4}', ResetPeriod::Monthly))
        ->toContain('Reset bulanan wajib memuat tahun ({YYYY}/{YY}) dan {MM}.');
});

test('BR-025 konsistensi periode lainnya', function (string $pattern, ResetPeriod $reset) {
    expect(PatternParser::errors($pattern, $reset))->not->toBe([]);
})->with([
    'yearly tanpa tahun' => ['A-{SEQ:4}', ResetPeriod::Yearly],
    'daily tanpa DD' => ['A-{YYYY}{MM}-{SEQ:4}', ResetPeriod::Daily],
    'monthly tanpa tahun' => ['A-{MM}-{SEQ:4}', ResetPeriod::Monthly],
]);

test('BR-025 token & literal tidak sah ditolak', function (string $pattern) {
    expect(PatternParser::errors($pattern, ResetPeriod::Never))->not->toBe([]);
})->with([
    'tanpa SEQ' => ['PJA-{YY}'],
    'SEQ ganda' => ['{SEQ:4}-{SEQ:4}'],
    'SEQ terlalu pendek' => ['A{SEQ:2}'],
    'SEQ terlalu panjang' => ['A{SEQ:9}'],
    'SEQ tanpa angka' => ['A{SEQ}'],
    'token asing' => ['A{BULAN}{SEQ:4}'],
    'PREFIX' => ['{PREFIX}{SEQ:4}'],
    'huruf kecil' => ['pja-{SEQ:4}'],
    'spasi' => ['PJA {SEQ:4}'],
    'kurung lepas' => ['PJA-{YY{SEQ:4}'],
    'kosong' => [''],
]);

test('BR-025 panjang hasil maksimal 30, {LOC} dihitung sepanjang kode lokasi terpanjang', function () {
    $pattern = 'KOR-{LOC}-{YYYY}{MM}-{SEQ:4}'; // 4 + LOC + 1 + 6 + 1 + 4

    expect(PatternParser::errors($pattern, ResetPeriod::Monthly, 10))->toBe([])
        ->and(PatternParser::errors($pattern, ResetPeriod::Monthly, 15)[0] ?? '')->toContain('maksimal 30');
});

test('format nomor dari pola', function () {
    $date = CarbonImmutable::parse('2026-11-03');

    expect(PatternParser::format('PJA-{YY}{SEQ:4}', 7, $date))->toBe('PJA-260007')
        ->and(PatternParser::format('KOR-{YYYY}{MM}-{SEQ:4}', 42, $date))->toBe('KOR-202611-0042')
        ->and(PatternParser::format('SJ/{LOC}/{DD}/{SEQ:3}', 5, $date, 'gdg01'))->toBe('SJ/GDG01/03/005');
});
