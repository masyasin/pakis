<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase order (15 §4.9, FR-A24, BR-160, T-079). Total & qty_base dihitung server (Money/Qty).
 * `qty_received_base` diisi penerimaan barang (T-080); `tax_total` diisi Paket F bila PKP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('stock_location_id');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->unsignedSmallInteger('payment_term_days');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'partially_received', 'received', 'closed', 'rejected', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 255)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'po_number_unique');
            $table->index(['supplier_id', 'status', 'order_date'], 'po_supplier_status_idx');
            $table->index(['status', 'expected_date'], 'po_status_expected_idx');
            $table->index(['stock_location_id', 'status'], 'po_sl_status_idx');
            $table->foreign('supplier_id', 'po_supplier_fk')->references('id')->on('suppliers');
            $table->foreign('stock_location_id', 'po_sl_fk')->references('id')->on('stock_locations');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->decimal('qty', 18, 3);
            $table->decimal('qty_base', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_pct', 7, 4)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->decimal('qty_received_base', 18, 3)->default(0);
            $table->string('note', 255)->nullable();

            $table->unique(['purchase_order_id', 'line_no'], 'pol_po_line_unique');
            $table->index('product_id', 'pol_product_idx');
            $table->foreign('purchase_order_id', 'pol_po_fk')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('product_id', 'pol_product_fk')->references('id')->on('products');
            $table->foreign('product_unit_id', 'pol_unit_fk')->references('id')->on('product_units');
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT pol_qty_chk CHECK (qty > 0 AND qty_base > 0 AND unit_price >= 0 AND qty_received_base >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
