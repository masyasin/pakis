<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kontak kios selain pemilik (15 §4.6, T-074): penjaga toko, bagian bayar. Dikelola lewat ubah kios
 * dan ikut pindah saat kios digabung (BR-166(5)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('name', 100);
            $table->string('role', 60)->nullable()->comment('penjaga toko, bagian bayar');
            $table->string('phone', 20)->nullable()->comment('format 62…');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('customer_id', 'cc_customer_idx');
            $table->foreign('customer_id', 'cc_customer_fk')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_contacts');
    }
};
