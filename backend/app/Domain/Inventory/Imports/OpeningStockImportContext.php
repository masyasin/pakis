<?php

namespace App\Domain\Inventory\Imports;

/**
 * Data rujukan impor saldo awal (T-086) yang dimuat sekali per fase, bukan per baris: berkas bisa
 * 30.000 baris (NFR-A01), jadi pencarian kode → id tidak boleh satu query per baris.
 */
final class OpeningStockImportContext
{
    /**
     * @param  array<string, int>  $locations  kode lokasi (huruf besar) → id; hanya gudang/toko aktif dalam scope Stok
     * @param  array<int, bool>  $lockedLocations  id lokasi yang sudah punya mutasi selain `opening_balance`
     * @param  array<string, array{id: int, tracks_batch: bool, tracks_expiry: bool, tracks_serial: bool}>  $products  SKU → produk
     * @param  array<string, int>  $bins  "{lokasi}|{KODE RAK}" → id rak aktif
     */
    public function __construct(
        public readonly array $locations,
        public readonly array $lockedLocations,
        public readonly array $products,
        public readonly array $bins,
        public readonly int $batchId,
    ) {}
}
