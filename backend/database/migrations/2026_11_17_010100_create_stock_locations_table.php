<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasi stok (15 §4.3, T-068, FR-A03, ADR-107): satu-satunya tempat stok. Gudang/toko menunjuk
 * `locations` Fase 0, kendaraan canvas menunjuk `vehicles`, `TRANSIT` = lokasi sistem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->enum('type', ['warehouse', 'store', 'vehicle', 'in_transit']);
            $table->unsignedBigInteger('location_id')->nullable()->comment('wajib untuk warehouse & store');
            $table->unsignedBigInteger('vehicle_id')->nullable()->comment('wajib untuk vehicle');
            $table->boolean('is_sellable')->default(true)->comment('false untuk in_transit');
            $table->boolean('is_system')->default(false)->comment('TRANSIT');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'stock_locations_code_unique');
            $table->unique('vehicle_id', 'stock_locations_vehicle_unique');
            $table->index(['type', 'is_active'], 'stock_locations_type_active_idx');
            $table->index('location_id', 'stock_locations_location_idx');
            $table->foreign('location_id', 'sl_location_fk')->references('id')->on('locations');
            $table->foreign('vehicle_id', 'sl_vehicle_fk')->references('id')->on('vehicles');
        });

        // Pengaman di DB selain validasi form: kombinasi tipe ↔ tautan harus sah (15 §4.3).
        DB::statement("ALTER TABLE stock_locations ADD CONSTRAINT sl_type_chk CHECK (
            (type IN ('warehouse','store') AND location_id IS NOT NULL AND vehicle_id IS NULL) OR
            (type = 'vehicle' AND vehicle_id IS NOT NULL) OR
            (type = 'in_transit' AND location_id IS NULL AND vehicle_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_locations');
    }
};
