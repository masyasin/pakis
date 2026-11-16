<?php

namespace App\Support\Import;

/** Hasil `ImportType::normalize()` untuk satu baris. */
final class NormalizedRow
{
    /**
     * @param  array<string, mixed>  $values  nilai ternormalisasi, di memori saja (boleh berisi NIK utuh); null bila gagal/kosong
     * @param  array<string, mixed>  $data  nilai untuk disimpan & ditampilkan (yang gagal: teks aslinya; NIK termasking)
     * @param  array<string, list<string>>  $errors  galat normalisasi per field
     */
    public function __construct(
        public readonly array $values,
        public readonly array $data,
        public readonly array $errors,
    ) {}
}
