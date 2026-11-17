<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock opname (15 §4.10, FR-A28, BR-162, T-083). Qty satuan dasar (ADR-102). `frozen_at` = saat
 * snapshot saldo (BR-162(1)); selisih dihitung terhadap snapshot + mutasi setelahnya (BR-162(2)).
 * Baris `stock_batch_id` NULL = batch ditemukan saat hitung (BR-162(7)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('stock_location_id');
            $table->enum('scope', ['full', 'bin', 'category', 'product']);
            $table->json('scope_ids')->nullable();
            $table->boolean('blind_count');
            $table->enum('status', ['draft', 'counting', 'review', 'pending_approval', 'posted', 'cancelled'])->default('draft');
            $table->dateTime('frozen_at', 3)->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->decimal('variance_value', 18, 2)->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'opn_number_unique');
            $table->index(['stock_location_id', 'status'], 'opn_sl_status_idx');
            $table->foreign('stock_location_id', 'opn_sl_fk')->references('id')->on('stock_locations');
        });

        Schema::create('stock_opname_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_opname_id');
            $table->unsignedBigInteger('stock_batch_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->unsignedBigInteger('stock_bin_id')->nullable();
            $table->decimal('qty_system_base', 18, 3)->default(0);
            $table->decimal('qty_counted_base', 18, 3)->nullable();
            $table->decimal('qty_recount_base', 18, 3)->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->unsignedBigInteger('recounted_by')->nullable();
            $table->timestamp('recounted_at')->nullable();
            $table->unsignedBigInteger('variance_reason_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('photo_path', 255)->nullable();

            $table->index('stock_opname_id', 'opnl_opn_idx');
            $table->index('stock_batch_id', 'opnl_batch_idx');
            $table->foreign('stock_opname_id', 'opnl_opn_fk')->references('id')->on('stock_opnames')->cascadeOnDelete();
            $table->foreign('product_id', 'opnl_product_fk')->references('id')->on('products');
        });
        // Lapis kedua: qty tidak pernah negatif (hitung fisik ≥ 0).
        DB::statement('ALTER TABLE stock_opname_lines ADD CONSTRAINT opnl_qty_chk CHECK (qty_system_base >= 0 AND (qty_counted_base IS NULL OR qty_counted_base >= 0) AND (qty_recount_base IS NULL OR qty_recount_base >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_lines');
        Schema::dropIfExists('stock_opnames');
    }
};
