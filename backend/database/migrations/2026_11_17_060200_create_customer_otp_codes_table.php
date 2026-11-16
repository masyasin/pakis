<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kode OTP kios (15 §4.6, BR-165, SEC-105, T-074). Kode tidak pernah disimpan: hanya HMAC-nya
 * (`code_hash`, PA-50). Percobaan dihitung atomik di kolom `attempts`. Pengiriman lewat kerangka OTP
 * Fase 0 kanal WhatsApp saja (PA-6); `OtpService` tidak dipakai karena per-`User`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->enum('purpose', ['activation', 'phone_change']);
            $table->string('phone', 20)->comment('nomor tujuan OTP');
            $table->string('code_hash', 255)->comment('HMAC-SHA256, kode tidak pernah disimpan');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedBigInteger('sent_by');
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'purpose', 'created_at'], 'cotp_customer_idx');
            $table->index(['phone', 'created_at'], 'cotp_phone_idx');
            $table->foreign('customer_id', 'cotp_customer_fk')->references('id')->on('customers');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_otp_codes');
    }
};
