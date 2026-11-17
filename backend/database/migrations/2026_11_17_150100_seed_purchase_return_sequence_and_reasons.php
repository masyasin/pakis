<?php

use App\Domain\Numbering\Models\DocumentSequence;
use App\Domain\Reference\Models\ReferenceItem;
use Database\Seeders\ReferenceSeeder;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Untuk DB yang sudah berisi data (T-081): penomoran `purchase_return` (`RTB-{YY}{MM}-{SEQ:4}`, reset
 * bulanan, `14` §8) dan item referensi `purchase_return_reason` (15 §4.1). Instalasi baru diisi seeder
 * lewat db:seed. Kedua seeder idempoten dan tidak menimpa nilai yang diubah admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DocumentSequence::query()->exists()) {
            (new SequenceSeeder)->run();
        }
        if (ReferenceItem::query()->exists()) {
            (new ReferenceSeeder)->run();
        }
    }

    public function down(): void {}
};
