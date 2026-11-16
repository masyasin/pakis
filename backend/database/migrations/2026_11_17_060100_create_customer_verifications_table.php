<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi kios (15 §4.6, BR-165/166(4), T-074): aktivasi (`activation`) dan ganti HP
 * (`phone_change`), masing-masing dokumen approval (`customer_activation` / `customer_phone_change`).
 * Satu baris = satu pengajuan yang bukti nomornya sudah lengkap (OTP cocok atau catatan telepon
 * kantor), jadi CHECK `cv_method_chk` tidak pernah perlu dilonggarkan (PA-49).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->enum('type', ['activation', 'phone_change']);
            $table->enum('method', ['otp', 'office_call']);
            $table->string('phone_target', 20)->comment('nomor yang diverifikasi');
            $table->string('phone_previous', 20)->nullable()->comment('untuk phone_change');
            $table->timestamp('otp_verified_at')->nullable();
            $table->string('office_call_note', 500)->nullable()->comment('wajib bila method = office_call');
            $table->string('photo_path', 255)->nullable()->comment('disk privat (SEC-108/109)');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('requested_credit_limit', 18, 2)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->unsignedBigInteger('submitted_by');
            $table->timestamps();

            $table->index(['customer_id', 'created_at'], 'cv_customer_idx');
            $table->index(['status', 'type'], 'cv_status_idx');
            $table->foreign('customer_id', 'cv_customer_fk')->references('id')->on('customers');
        });

        DB::statement("ALTER TABLE customer_verifications ADD CONSTRAINT cv_method_chk CHECK (method <> 'office_call' OR office_call_note IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_verifications');
    }
};
