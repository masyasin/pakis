<?php

use App\Domain\Numbering\Models\DocumentSequence;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Penomoran `supplier` (`SUP-{SEQ:4}`, `14` §8, T-072) untuk DB yang sudah berisi data. Instalasi
 * baru diisi SequenceSeeder lewat db:seed. Seeder idempoten (firstOrCreate): pola yang sudah
 * diubah admin tidak ditimpa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DocumentSequence::query()->exists()) {
            return;
        }

        (new SequenceSeeder)->run();
    }

    public function down(): void {}
};
