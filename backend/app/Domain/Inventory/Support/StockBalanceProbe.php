<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;

/**
 * Apakah lokasi stok / rak masih menyimpan barang. Lokasi atau rak bersaldo tidak boleh
 * dinonaktifkan (15 §4.3, kriteria T-068) karena stoknya jadi tidak terjangkau dokumen apa pun.
 *
 * Implementasi: `LedgerStockBalanceProbe` (pembaca `stock_batches`, T-077). Antarmuka dipertahankan
 * agar test bisa memalsukan saldo tanpa menulis buku stok.
 */
interface StockBalanceProbe
{
    public function locationHasStock(StockLocation $location): bool;

    public function binHasStock(StockBin $bin): bool;
}
