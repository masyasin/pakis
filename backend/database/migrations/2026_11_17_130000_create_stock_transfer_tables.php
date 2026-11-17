<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mutasi antar lokasi (15 §4.10, FR-A27, BR-161, T-082). Qty selalu satuan dasar (ADR-102).
 * `source_batch_id` NULL = FEFO otomatis saat kirim; `transit_batch_id` = batch bayangan di TRANSIT;
 * `dest_batch_id` diisi saat terima. `fefo_override` menandai pilihan batch manual (BR-156).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('from_stock_location_id');
            $table->unsignedBigInteger('to_stock_location_id');
            $table->enum('status', ['draft', 'shipped', 'received', 'received_with_variance', 'closed', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('shipped_by')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'st_number_unique');
            $table->index(['from_stock_location_id', 'status'], 'st_from_status_idx');
            $table->index(['to_stock_location_id', 'status'], 'st_to_status_idx');
            $table->foreign('from_stock_location_id', 'st_from_fk')->references('id')->on('stock_locations');
            $table->foreign('to_stock_location_id', 'st_to_fk')->references('id')->on('stock_locations');
            $table->foreign('vehicle_id', 'st_vehicle_fk')->references('id')->on('vehicles');
        });
        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT st_diff_chk CHECK (from_stock_location_id <> to_stock_location_id)');

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_transfer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('source_batch_id')->nullable();
            $table->unsignedBigInteger('transit_batch_id')->nullable();
            $table->unsignedBigInteger('dest_batch_id')->nullable();
            $table->decimal('qty_requested_base', 18, 3);
            $table->decimal('qty_shipped_base', 18, 3)->nullable();
            $table->decimal('qty_received_base', 18, 3)->nullable();
            $table->string('variance_reason', 255)->nullable();
            $table->unsignedBigInteger('dest_bin_id')->nullable();
            $table->boolean('fefo_override')->default(false);

            $table->index('stock_transfer_id', 'stl_st_idx');
            $table->foreign('stock_transfer_id', 'stl_st_fk')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('product_id', 'stl_product_fk')->references('id')->on('products');
        });
        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stl_qty_chk CHECK (qty_requested_base > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
