<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran dokumen (04 §2.5, BR-025, ADR-009). Counter per (urutan, periode[@lokasi]) dikunci
 * `FOR UPDATE` di transaksi pemanggil, sehingga dua permintaan bersamaan tidak mendapat nomor sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique('document_sequences_code_unique')->comment("'employee', 'attendance_correction'");
            $table->string('name', 100);
            $table->string('pattern', 100)->comment("mis. 'PJA-{YY}{SEQ:4}'");
            $table->enum('reset_period', ['never', 'yearly', 'monthly', 'daily'])->default('never');
            $table->boolean('is_system')->default(true)->comment('code tidak bisa diubah/hapus');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('document_sequence_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_sequence_id')->constrained('document_sequences', indexName: 'dsc_seq_fk');
            $table->string('scope_key', 40)->comment("periode (+ @kode lokasi bila pola memuat {LOC}); '-' bila never");
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['document_sequence_id', 'scope_key'], 'dsc_seq_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequence_counters');
        Schema::dropIfExists('document_sequences');
    }
};
