<?php

use Database\Seeders\TransitStockLocationSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Lokasi stok sistem `TRANSIT` (15 §4.3, T-068) untuk DB yang sudah berjalan (dev, staging,
 * produksi) yang tidak menjalankan db:seed lagi. Seeder idempoten, jadi instalasi baru yang juga
 * memanggilnya lewat DatabaseSeeder tetap berakhir dengan tepat satu TRANSIT.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TransitStockLocationSeeder)->run();
    }

    /** Tabelnya dihapus migrasi pembuatnya; baris sistem tidak dihapus terpisah. */
    public function down(): void {}
};
