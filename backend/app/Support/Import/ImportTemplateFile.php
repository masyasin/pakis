<?php

namespace App\Support\Import;

use App\Support\Export\FormulaEscaper;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Templat .xlsx umum untuk tipe impor tanpa templat khusus: baris 1 = judul kolom (label field,
 * wajib diberi "*"), baris 2 = petunjuk pengisian. Judul memakai label sehingga langsung dikenali
 * sinonim saat diunggah kembali. Impor pegawai memakai templat sendiri (ImportTemplate).
 */
final class ImportTemplateFile
{
    public function write(ImportType $type, string $absolutePath): void
    {
        $fields = $type->fields();
        $writer = new Writer;
        $writer->openToFile($absolutePath);

        try {
            $titles = array_map(fn (string $f) => $fields->label($f).($fields->isRequired($f) ? '*' : ''), $fields->keys());
            $hints = array_map(fn (string $f) => $fields->fields[$f]['hint'], $fields->keys());

            $writer->addRow(Row::fromValuesWithStyle(array_map(FormulaEscaper::escape(...), $titles), (new Style)->withFontBold(true)));
            $writer->addRow(Row::fromValuesWithStyle(array_map(FormulaEscaper::escape(...), $hints), (new Style)->withFontItalic(true)));
        } finally {
            $writer->close();
        }
    }
}
