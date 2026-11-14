<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referensi dinamis (04 §2.6, BR-026). Grup dikunci di ReferenceGroup; item `is_system` kodenya
 * dipakai logika sehingga tidak bisa diubah/dihapus dari layar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_items', function (Blueprint $table) {
            $table->id();
            $table->string('group_code', 50)->comment('location_type | employment_status | correction_reason | offboarding_reason');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false)->comment('code dipakai logika, tidak bisa diubah/hapus');
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['group_code', 'code'], 'ref_group_code_unique');
            $table->index(['group_code', 'is_active', 'sort_order'], 'ref_group_active_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_items');
    }
};
