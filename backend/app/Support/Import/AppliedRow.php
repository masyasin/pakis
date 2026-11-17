<?php

namespace App\Support\Import;

/** Hasil `ImportType::apply()` untuk satu baris. */
final class AppliedRow
{
    /**
     * @param  ImportRowStatus  $status  `created`, `updated`, atau `skipped`
     * @param  array<string, mixed>  $data  ditambahkan ke `import_rows.data` (mis. id data yang dibuat, peringatan).
     *                                      Kunci `activated` = true dihitung ke audit `import.processed` (T-085: kios
     *                                      aktif-terverifikasi dari impor tercatat dengan jumlah barisnya).
     */
    public function __construct(
        public readonly ImportRowStatus $status,
        public readonly array $data = [],
    ) {}
}
