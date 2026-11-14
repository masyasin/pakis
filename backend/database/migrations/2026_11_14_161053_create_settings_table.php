<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nilai site setting yang diubah admin (04 §2.4). Kunci yang tidak punya baris memakai default
 * dari SettingRegistry; "Kembalikan default" = menghapus barisnya (BR-024).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique('settings_key_unique');
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'settings_updated_by_fk');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
