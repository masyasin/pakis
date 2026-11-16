<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rak/bin per lokasi stok (15 §4.3, T-068, FR-A04). Hanya untuk gudang/toko: kendaraan & TRANSIT
 * tidak berak (15 §4.8, `stock_bin_id` selalu NULL di sana). Tanpa soft delete: rak dinonaktifkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_bins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_location_id');
            $table->string('code', 20)->comment('R2-04');
            $table->string('name', 100)->nullable();
            $table->enum('zone', ['general', 'pesticide', 'restricted', 'quarantine'])->default('general');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['stock_location_id', 'code'], 'stock_bins_loc_code_unique');
            $table->index(['stock_location_id', 'zone', 'is_active'], 'stock_bins_loc_zone_idx');
            $table->foreign('stock_location_id', 'sb_loc_fk')->references('id')->on('stock_locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_bins');
    }
};
