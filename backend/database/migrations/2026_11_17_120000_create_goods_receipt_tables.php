<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penerimaan barang + nomor seri (15 §4.9, FR-A25, BR-103, BR-144, BR-158, T-080). Qty dasar &
 * biaya dihitung server (Qty/UnitCost); `unit_cost` per satuan dasar berasal dari baris PO (SEC-104).
 * `stock_batch_id` baris dan nomor seri diisi saat posting lewat StockLedger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('purchase_order_id')->nullable();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('stock_location_id');
            $table->dateTime('received_at');
            $table->string('supplier_delivery_no', 60)->nullable();
            $table->string('vehicle_plate', 15)->nullable();
            $table->enum('status', ['draft', 'checked', 'pending_approval', 'posted', 'rejected'])->default('draft');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users');
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'gr_number_unique');
            $table->index('purchase_order_id', 'gr_po_idx');
            $table->index(['stock_location_id', 'status', 'received_at'], 'gr_sl_status_idx');
            $table->foreign('purchase_order_id', 'gr_po_fk')->references('id')->on('purchase_orders');
            $table->foreign('supplier_id', 'gr_supplier_fk')->references('id')->on('suppliers');
            $table->foreign('stock_location_id', 'gr_sl_fk')->references('id')->on('stock_locations');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('goods_receipt_id');
            $table->unsignedBigInteger('purchase_order_line_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->decimal('qty_received', 18, 3);
            $table->decimal('qty_received_base', 18, 3);
            $table->decimal('qty_rejected_base', 18, 3)->default(0);
            $table->unsignedBigInteger('reject_reason_id')->nullable();
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->string('registration_no_seen', 60)->nullable();
            $table->unsignedBigInteger('stock_bin_id')->nullable();
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('shelf_life_months', 5, 1)->nullable();
            $table->enum('line_status', ['ok', 'short_shelf_life', 'registration_mismatch'])->default('ok');
            $table->unsignedBigInteger('stock_batch_id')->nullable();

            $table->index('goods_receipt_id', 'grl_gr_idx');
            $table->index(['product_id', 'batch_no'], 'grl_product_batch_idx');
            $table->foreign('goods_receipt_id', 'grl_gr_fk')->references('id')->on('goods_receipts')->cascadeOnDelete();
            $table->foreign('purchase_order_line_id', 'grl_pol_fk')->references('id')->on('purchase_order_lines');
            $table->foreign('product_id', 'grl_product_fk')->references('id')->on('products');
            $table->foreign('product_unit_id', 'grl_unit_fk')->references('id')->on('product_units');
            $table->foreign('reject_reason_id', 'grl_reason_fk')->references('id')->on('reference_items');
            $table->foreign('stock_bin_id', 'grl_bin_fk')->references('id')->on('stock_bins');
            $table->foreign('stock_batch_id', 'grl_batch_fk')->references('id')->on('stock_batches');
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_qty_chk CHECK (qty_received >= 0 AND qty_rejected_base >= 0 AND (qty_received > 0 OR qty_rejected_base > 0))');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT grl_reject_chk CHECK (qty_rejected_base = 0 OR reject_reason_id IS NOT NULL)');

        Schema::create('serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('serial_no', 80);
            $table->unsignedBigInteger('goods_receipt_line_id')->nullable();
            $table->unsignedBigInteger('stock_batch_id')->nullable();
            $table->enum('status', ['in_stock', 'reserved', 'sold', 'returned', 'in_service', 'returned_to_principal', 'scrapped'])->default('in_stock');
            $table->enum('input_method', ['scan', 'manual'])->default('scan');
            $table->string('label_photo_path', 255)->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'serial_no'], 'serial_product_no_unique');
            $table->index(['status', 'sold_at'], 'serial_status_sold_idx');
            $table->index('stock_batch_id', 'serial_batch_idx');
            $table->foreign('product_id', 'serial_product_fk')->references('id')->on('products');
            $table->foreign('goods_receipt_line_id', 'serial_grl_fk')->references('id')->on('goods_receipt_lines');
            $table->foreign('stock_batch_id', 'serial_batch_fk')->references('id')->on('stock_batches');
        });
        DB::statement("ALTER TABLE serial_numbers ADD CONSTRAINT serial_manual_chk CHECK (input_method = 'scan' OR label_photo_path IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
