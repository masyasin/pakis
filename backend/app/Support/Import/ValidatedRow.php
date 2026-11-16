<?php

namespace App\Support\Import;

/** Hasil `ImportType::validate()` untuk satu baris. */
final class ValidatedRow
{
    /**
     * @param  array<string, mixed>  $values  nilai siap pakai di memori (boleh berisi NIK utuh), untuk `rowKey()` & `apply()`
     * @param  array<string, mixed>  $data  disimpan di `import_rows.data` (tanpa data rahasia)
     * @param  array<string, list<string>>  $errors  galat akhir per field (`_row` = seluruh baris)
     */
    public function __construct(
        public readonly array $values,
        public readonly array $data,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
