<?php

namespace App\Domain\Customer\Imports;

/** Data sekali per lintasan impor kios (T-085): rujukan ber-scope & syarat aktif-terverifikasi. */
final class CustomerImportContext
{
    /**
     * @param  array<string, int>  $types  kode/nama jenis pelanggan (huruf besar) → id
     * @param  array<string, int>  $areas  kode area dalam scope pengimpor → id
     * @param  array<string, int>  $priceLists  kode daftar harga tingkat aktif → id
     */
    public function __construct(
        public readonly array $types,
        public readonly array $areas,
        public readonly array $priceLists,
        public readonly bool $canVerify,
        public readonly bool $passwordConfirmed,
        public readonly int $batchId,
    ) {}
}
