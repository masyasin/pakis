<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Models\StockBin;
use App\Domain\Inventory\Models\StockLocation;

/**
 * Apakah lokasi stok / rak masih menyimpan barang. Lokasi atau rak bersaldo tidak boleh
 * dinonaktifkan (15 §4.3, kriteria T-068) karena stoknya jadi tidak terjangkau dokumen apa pun.
 *
 * Buku stok baru ada di T-077: sampai itu, implementasi bawaan `NoStockBalanceProbe` selalu
 * menjawab "kosong", dan T-077 mengganti binding-nya dengan pembaca `stock_batches`.
 */
interface StockBalanceProbe
{
    public function locationHasStock(StockLocation $location): bool;

    public function binHasStock(StockBin $bin): bool;
}
