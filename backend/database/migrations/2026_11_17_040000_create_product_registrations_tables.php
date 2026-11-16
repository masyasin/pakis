<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Izin edar produk + tabel umum pengingat (15 §4.1 `reminder_logs`, §4.4 `product_registrations`,
 * FR-A10, BR-152, T-071). `reminder_logs` dipakai juga T-078 (ED batch) dan T-082 (mutasi): UNIQUE
 * per subjek per ambang membuat job berulang tidak mengirim pengingat ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');
            $table->string('reminder_key', 40);
            $table->timestamp('sent_at');

            $table->unique(['subject_type', 'subject_id', 'reminder_key'], 'reminder_unique');
        });

        Schema::create('product_registrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('registration_no', 60);
            $table->string('holder_name', 150)->nullable();
            // Tanggal sipil lokal (bukan instan UTC): izin berlaku sampai akhir hari `valid_until`.
            $table->date('issued_at')->nullable();
            $table->date('valid_until');
            $table->string('document_path', 255)->nullable();
            $table->enum('status', ['active', 'expired', 'revoked'])->default('active');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['product_id', 'registration_no'], 'preg_product_no_unique');
            $table->index(['product_id', 'valid_until'], 'preg_product_valid_idx');
            $table->index(['status', 'valid_until'], 'preg_status_valid_idx');
            $table->foreign('product_id', 'preg_product_fk')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_registrations');
        Schema::dropIfExists('reminder_logs');
    }
};
