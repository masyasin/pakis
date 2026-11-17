<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retur ke prinsipal (15 §4.9, FR-A26, BR-155, BR-159, T-081). `unit_cost` baris = biaya beli batch
 * (`stock_batches.receipt_unit_cost`, cadangan HPP rata-rata) per satuan dasar; `reservation_id` diisi
 * saat disetujui (BR-155) dan dikonsumsi saat dikirim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('stock_location_id');
            $table->unsignedBigInteger('goods_receipt_id')->nullable();
            $table->unsignedBigInteger('reason_id');
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'shipped', 'closed', 'rejected', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'prt_number_unique');
            $table->index(['supplier_id', 'status'], 'prt_supplier_status_idx');
            $table->foreign('supplier_id', 'prt_supplier_fk')->references('id')->on('suppliers');
            $table->foreign('stock_location_id', 'prt_sl_fk')->references('id')->on('stock_locations');
            $table->foreign('goods_receipt_id', 'prt_gr_fk')->references('id')->on('goods_receipts');
            $table->foreign('reason_id', 'prt_reason_fk')->references('id')->on('reference_items');
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('stock_batch_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty_base', 18, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->unsignedBigInteger('reservation_id')->nullable();

            $table->index('purchase_return_id', 'prtl_prt_idx');
            $table->foreign('purchase_return_id', 'prtl_prt_fk')->references('id')->on('purchase_returns')->cascadeOnDelete();
            $table->foreign('stock_batch_id', 'prtl_batch_fk')->references('id')->on('stock_batches');
            $table->foreign('product_id', 'prtl_product_fk')->references('id')->on('products');
            $table->foreign('reservation_id', 'prtl_reservation_fk')->references('id')->on('stock_reservations');
        });
        DB::statement('ALTER TABLE purchase_return_lines ADD CONSTRAINT prtl_qty_chk CHECK (qty_base > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
    }
};
