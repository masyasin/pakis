<?php

use App\Domain\Numbering\Models\DocumentSequence;
use Database\Seeders\SequenceSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Penomoran `goods_receipt` (`GR-{YY}{MM}-{SEQ:4}`, reset bulanan, `14` §8, T-080) untuk DB yang
 * sudah berisi data. Instalasi baru diisi SequenceSeeder lewat db:seed (idempoten).
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
