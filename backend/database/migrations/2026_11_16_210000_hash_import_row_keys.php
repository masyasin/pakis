<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T-085A (BR-170, PA-15): `import_rows.row_key` menyimpan sha256 kunci gabungan, bukan kunci
 * mentah. Kunci tipe Paket A (mis. saldo awal lokasi+SKU+batch+bin) bisa melebihi 100 karakter,
 * dan kunci mentah pegawai memuat hash NIK yang tidak perlu disimpan dalam bentuk terbaca.
 * Baris lama di-hash di tempat (`SHA2` MariaDB = `hash('sha256')` PHP, hex huruf kecil).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->hashExisting();

        Schema::table('import_rows', function (Blueprint $table) {
            $table->char('row_key', 64)->nullable()->comment('sha256 kunci gabungan tipe impor (BR-170)')->change();
        });
    }

    /** Langkah data saja (tanpa DDL), dipisah agar bisa diuji di dalam transaksi test. */
    public function hashExisting(): void
    {
        DB::table('import_rows')->whereNotNull('row_key')->update(['row_key' => DB::raw('SHA2(row_key, 256)')]);
    }

    /** Hanya lebar kolom yang dikembalikan; hash tidak bisa dibalik ke kunci mentah. */
    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->string('row_key', 100)->nullable()->comment('nik_hash, atau employee_number, atau hash(nama+tgl lahir)')->change();
        });
    }
};
