<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kendaraan minimal (15 §4.3, T-068, FR-A05). Kolom armada (STNK, KIR, BBM, servis) ditambah Paket D;
 * di sini cukup untuk menautkan lokasi stok canvas (ADR-107).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_no', 15)->comment('tanpa spasi, huruf besar: K9021AB');
            $table->string('name', 100);
            $table->enum('vehicle_type', ['truck', 'pickup', 'van', 'motorcycle', 'other']);
            $table->boolean('is_canvas')->default(false);
            $table->unsignedBigInteger('home_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            // Unik termasuk kendaraan terhapus: plat fisik tidak pernah dipakai dua kendaraan.
            $table->unique('plate_no', 'vehicles_plate_unique');
            $table->foreign('home_location_id', 'vehicles_home_fk')->references('id')->on('locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
