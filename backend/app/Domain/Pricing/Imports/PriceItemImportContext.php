<?php

namespace App\Domain\Pricing\Imports;

use App\Domain\Pricing\Models\PriceList;

/** Data sekali per lintasan impor harga (T-085): daftar harga aktif & draf tujuan per daftar. */
final class PriceItemImportContext
{
    /** @var array<int, int> id daftar harga → id versi draf tujuan (diisi saat proses) */
    public array $drafts = [];

    /** @param  array<string, PriceList>  $lists  kode (huruf besar) → daftar harga aktif */
    public function __construct(
        public readonly array $lists,
        public readonly int $batchId,
    ) {}
}
