<?php

namespace App\Support\Import;

/** Hasil `ImportType::processOrder()`: urutan proses dan baris yang ditolak sebelum diproses. */
final class ImportOrder
{
    /**
     * @param  list<int>  $rows  nomor baris dalam urutan proses (termasuk yang ditolak)
     * @param  array<int, array<string, list<string>>>  $rejected  nomor baris → galat per field
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $rejected = [],
    ) {}

    /** @param  array<int, mixed>  $raws */
    public static function asIs(array $raws): self
    {
        $rows = array_keys($raws);
        sort($rows);

        return new self($rows);
    }
}
