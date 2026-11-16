<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prinsipal, supplier, produk dipasok (15 §4.5, FR-A11, SEC-108, T-072). Rekening supplier disimpan
 * terenkripsi (`bank_account_no_enc`, cast `encrypted`); kolom plaintext tidak pernah ada.
 * `supplier_products.last_price` diisi penerimaan barang (T-080), bukan form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('website', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'principals_code_unique');
            $table->index(['is_active', 'name'], 'principals_active_name_idx');
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->char('village_code', 13)->nullable();
            $table->unsignedSmallInteger('payment_term_days')->default(30);
            $table->string('bank_name', 60)->nullable();
            $table->text('bank_account_no_enc')->nullable();
            $table->string('bank_account_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'suppliers_code_unique');
            $table->index(['is_active', 'name'], 'suppliers_active_name_idx');
            $table->foreign('principal_id', 'suppliers_principal_fk')->references('id')->on('principals');
            $table->foreign('village_code', 'suppliers_village_fk')->references('code')->on('villages');
        });

        Schema::create('supplier_products', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->string('supplier_sku', 40)->nullable();
            $table->decimal('last_price', 18, 2)->nullable();
            $table->unsignedBigInteger('last_price_unit_id')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['supplier_id', 'product_id']);
            $table->index(['product_id', 'is_preferred'], 'sp_product_idx');
            $table->foreign('supplier_id', 'sp_supplier_fk')->references('id')->on('suppliers');
            $table->foreign('product_id', 'sp_product_fk')->references('id')->on('products');
        });

        // 15 §4.5: constraint prinsipal produk menyusul setelah tabel `principals` ada (kolomnya dari T-070).
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('principal_id', 'products_principal_fk')->references('id')->on('principals');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign('products_principal_fk');
        });
        Schema::dropIfExists('supplier_products');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('principals');
    }
};
