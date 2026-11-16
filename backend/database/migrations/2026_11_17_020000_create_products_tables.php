<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Produk, satuan produk, barcode (15 §4.4, FR-A07–A09, BR-150/151, T-070). `principal_id` dibuat
 * TANPA constraint FK: tabel `principals` baru ada di T-072, yang menambah `products_principal_fk`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 30);
            $table->string('name', 150);
            $table->unsignedBigInteger('product_category_id');
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('active_ingredient', 255)->nullable();
            $table->unsignedBigInteger('formulation_id')->nullable();
            $table->string('package_size', 40)->nullable();
            $table->unsignedBigInteger('base_unit_id');
            $table->text('description')->nullable();
            $table->text('usage_note')->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->boolean('tracks_batch');
            $table->boolean('tracks_expiry');
            $table->boolean('tracks_serial');
            $table->boolean('requires_registration');
            $table->boolean('is_restricted')->default(false);
            $table->string('restricted_note', 255)->nullable();
            $table->unsignedSmallInteger('warranty_months')->nullable();
            $table->unsignedSmallInteger('min_shelf_life_months')->nullable();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->enum('status', ['active', 'inactive', 'discontinued'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('sku', 'products_sku_unique');
            $table->index(['status', 'name'], 'products_status_name_idx');
            $table->index(['product_category_id', 'status'], 'products_category_status_idx');
            $table->index('principal_id', 'products_principal_idx');
            $table->foreign('product_category_id', 'products_category_fk')->references('id')->on('product_categories');
            $table->foreign('base_unit_id', 'products_base_unit_fk')->references('id')->on('reference_items');
            $table->foreign('formulation_id', 'products_formulation_fk')->references('id')->on('reference_items');
        });
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_expiry_chk CHECK (tracks_expiry = 0 OR tracks_batch = 1)');

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id');
            $table->decimal('factor', 18, 3);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_default_sales')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['product_id', 'unit_id'], 'pu_product_unit_unique');
            $table->foreign('product_id', 'pu_product_fk')->references('id')->on('products');
            $table->foreign('unit_id', 'pu_unit_fk')->references('id')->on('reference_items');
        });
        DB::statement('ALTER TABLE product_units ADD CONSTRAINT pu_factor_chk CHECK (factor > 0 AND (is_base = 0 OR factor = 1))');

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->string('barcode', 64);
            $table->timestamps();

            $table->unique('barcode', 'product_barcodes_barcode_unique');
            $table->foreign('product_id', 'pb_product_fk')->references('id')->on('products');
            $table->foreign('product_unit_id', 'pb_unit_fk')->references('id')->on('product_units');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
    }
};
