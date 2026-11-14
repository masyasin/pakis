<?php

namespace App\Support\Export;

/**
 * Cegah formula injection di berkas ekspor (BR-005, SEC-051).
 *
 * Nama/alamat/catatan diisi pengguna dan bisa berisi `=HYPERLINK("http://jahat")`. Excel/LibreOffice
 * mengeksekusinya sebagai formula, dan OpenSpout sendiri menjadikan string berawalan `=` sebagai
 * FormulaCell. Awalan `'` membuatnya tampil sebagai teks biasa. Hanya string yang diubah;
 * angka asli (int/float) tetap angka.
 */
final class FormulaEscaper
{
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::DANGEROUS_PREFIXES, true) ? "'".$value : $value;
    }
}
