<?php

namespace App\Support\Import;

use App\Support\Export\FormulaEscaper;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Berkas galat impor (.xlsx, 05 §4.7): baris `invalid` dengan judul kolom berkas asli + kolom
 * "Pesan galat", agar bisa diperbaiki lalu diunggah ulang. Dibuat dari `import_rows` (bukan berkas
 * asli yang sudah dihapus setelah proses), jadi NIK termasking (SEC-051): kolom NIK harus diketik
 * ulang. Semua sel di-escape agar teks seperti `=HYPERLINK(...)` tidak menjadi formula.
 */
final class ImportErrorFile
{
    private const CHUNK = 500;

    /** @return int jumlah baris galat yang ditulis */
    public function write(ImportBatch $batch, string $absolutePath): int
    {
        $headers = $batch->headers();
        $fields = ImportRegistry::forBatch($batch)->fields();
        $columns = $batch->columnMapping();
        ksort($columns);

        $writer = new Writer;
        $writer->openToFile($absolutePath);

        try {
            $titles = ['Baris'];
            foreach ($columns as $index => $field) {
                $header = trim($headers[$index] ?? '');
                $titles[] = $header !== '' ? $header : $fields->label($field);
            }
            $titles[] = 'Pesan galat';
            $writer->addRow(Row::fromValuesWithStyle(array_map(FormulaEscaper::escape(...), $titles), (new Style)->withFontBold(true)));

            $count = 0;
            $rows = ImportRow::query()->where('import_batch_id', $batch->id)
                ->where('status', ImportRowStatus::Invalid)->orderBy('row_number');
            foreach ($rows->lazy(self::CHUNK) as $row) {
                /** @var ImportRow $row */
                $values = [$row->row_number];
                foreach ($columns as $field) {
                    $value = $row->data[$field] ?? null;
                    $values[] = is_scalar($value) ? $value : null;
                }
                $values[] = self::message($fields, $row->errors ?? []);

                $writer->addRow(Row::fromValues(array_map(FormulaEscaper::escape(...), $values)));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return $count;
    }

    /** @param  array<string, list<string>>  $errors */
    public static function message(ImportFields $fields, array $errors): string
    {
        $parts = [];
        foreach ($errors as $field => $messages) {
            $label = $fields->errorLabel($field);
            foreach ($messages as $message) {
                $parts[] = "{$label}: {$message}";
            }
        }

        return implode('; ', $parts);
    }
}
