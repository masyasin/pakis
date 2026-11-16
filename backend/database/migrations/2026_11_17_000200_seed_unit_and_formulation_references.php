<?php

use App\Domain\Reference\Models\ReferenceItem;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Item sistem grup `unit` & `formulation` (15 §4.1, T-069) untuk DB yang sudah berisi data (dev,
 * staging). Instalasi baru (tabel referensi masih kosong) diisi ReferenceSeeder lewat db:seed, jadi
 * dilewati di sini. Seeder idempoten (firstOrCreate), sehingga grup lama tidak berubah dan satu
 * daftar item tetap menjadi sumbernya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! ReferenceItem::query()->exists()) {
            return;
        }

        (new ReferenceSeeder)->run();
    }

    /** Item sistem tidak dihapus: bisa sudah dirujuk data lain. */
    public function down(): void {}
};
