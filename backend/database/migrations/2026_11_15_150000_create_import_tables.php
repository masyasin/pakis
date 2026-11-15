<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impor pegawai (04 §2.10, T-027): profil pemetaan, batch unggahan, dan baris hasil validasi.
 * `export_files` dari tabel yang sama sudah dibuat T-006.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->comment("'employees'");
            $table->string('name', 100);
            $table->char('header_signature', 64)->comment('sha256 dari header ternormalisasi');
            $table->json('mapping')->comment('{"Nama Lengkap":"full_name", ...}');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['type', 'name'], 'imp_profile_type_name_unique');
            $table->index(['type', 'header_signature'], 'imp_profile_sig_idx');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('original_name', 255);
            $table->string('stored_path', 255)->comment('disk private');
            $table->char('file_hash', 64);
            $table->enum('status', ['uploaded', 'mapped', 'validated', 'processing', 'completed', 'failed', 'cancelled'])->default('uploaded');
            $table->json('mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['type', 'status', 'created_at'], 'imp_batch_type_status_idx');
            $table->index(['type', 'file_hash'], 'imp_batch_hash_idx');
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('row_key', 100)->nullable()->comment('nik_hash, atau employee_number, atau hash(nama+tgl lahir)');
            $table->json('data')->comment('nilai SUDAH dipetakan; NIK disimpan termasking');
            $table->json('errors')->nullable();
            $table->enum('status', ['valid', 'invalid', 'created', 'updated', 'skipped']);

            $table->unique(['import_batch_id', 'row_number'], 'imp_rows_batch_row_unique');
            $table->index(['import_batch_id', 'status'], 'imp_rows_batch_status_idx');
            $table->foreign('import_batch_id', 'imp_rows_batch_fk')->references('id')->on('import_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_mapping_profiles');
    }
};
