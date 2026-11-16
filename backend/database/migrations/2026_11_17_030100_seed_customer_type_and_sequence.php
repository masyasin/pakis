<?php

use App\Domain\Numbering\Models\DocumentSequence;
use App\Domain\Reference\Models\ReferenceItem;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Grup referensi `customer_type` dan penomoran `customer` (`KIO-{SEQ:5}`, 15 §4.1, T-073) untuk DB
 * yang sudah berisi data. Instalasi baru diisi seeder lewat db:seed. Kedua seeder idempoten
 * (firstOrCreate): item/pola yang sudah diubah admin tidak ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (ReferenceItem::query()->exists()) {
            (new ReferenceSeeder)->run();
        }

        if (DocumentSequence::query()->exists()) {
            (new SequenceSeeder)->run();
        }
    }

    /** Item sistem & pola penomoran tidak dihapus: bisa sudah dirujuk data lain. */
    public function down(): void {}
};
