<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * T-087A (keputusan Mas 18 Nov 2026, PA-73): grafik dasbor "Mutasi stok per hari" menghitung mutasi
 * per tanggal bisnis untuk semua produk di lokasi dalam scope. Tanpa index ber-awalan `business_date`,
 * uji volume 1 juta mutasi memindai seluruh tabel (±0,8 detik per muat dasbor). Index ini membuat query
 * hanya membaca rentang tanggal grafik. Tabel append-only (SEC-102): hanya index, tanpa ubah data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['business_date', 'stock_location_id'], 'smov_date_loc_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('smov_date_loc_idx');
        });
    }
};
