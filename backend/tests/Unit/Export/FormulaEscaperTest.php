<?php

use App\Support\Export\FormulaEscaper;

/*
 * SEC-051 / BR-005: nilai yang bisa dieksekusi spreadsheet diberi awalan ' sehingga menjadi teks.
 */

test('SEC-051 nilai berawalan karakter formula diberi awalan kutip', function (string $value) {
    expect(FormulaEscaper::escape($value))->toBe("'".$value);
})->with([
    'hyperlink' => ['=HYPERLINK("http://jahat.test","klik")'],
    'plus' => ['+62 812'],
    'minus' => ['-1+1'],
    'at' => ['@SUM(A1)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
]);

test('nilai aman tidak diubah', function (mixed $value) {
    expect(FormulaEscaper::escape($value))->toBe($value);
})->with([
    'teks biasa' => ['Agus Salim'],
    'kosong' => [''],
    'angka bulat' => [-5],
    'desimal' => [1.5],
    'null' => [null],
    'boolean' => [true],
    'formula di tengah' => ['Total =A1'],
]);
