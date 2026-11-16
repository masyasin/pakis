<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar harga berversi (15 §4.7, FR-A19, BR-168, SEC-106, ADR-108, T-075).
 *
 * - `price_lists.default_key` (generated) + UNIQUE: hanya boleh satu list `default`.
 * - Versi append-only: item versi selain `draft` dikunci trigger `price_list_items_lock_*` (lapis kedua
 *   setelah Policy/Action), sehingga harga yang sudah terbit tidak bisa diubah lewat jalur apa pun.
 * - FK `customers.price_list_id` (kolomnya dibuat T-073) ditambahkan di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->enum('kind', ['tier', 'area', 'store', 'default']);
            $table->unsignedBigInteger('sales_area_id')->nullable();
            $table->unsignedBigInteger('stock_location_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->tinyInteger('default_key')->nullable()->storedAs("IF(kind = 'default', 1, NULL)");
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('code', 'price_lists_code_unique');
            $table->unique('default_key', 'price_lists_one_default');
            $table->foreign('sales_area_id', 'pl_area_fk')->references('id')->on('sales_areas');
            $table->foreign('stock_location_id', 'pl_sl_fk')->references('id')->on('stock_locations');
        });
        // List area wajib menunjuk area; list toko wajib menunjuk lokasi stok toko.
        DB::statement("ALTER TABLE price_lists ADD CONSTRAINT pl_kind_target_chk CHECK ((kind <> 'area' OR sales_area_id IS NOT NULL) AND (kind <> 'store' OR stock_location_id IS NOT NULL))");

        Schema::create('price_list_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('price_list_id');
            $table->unsignedInteger('version_no');
            $table->dateTime('effective_from');
            $table->dateTime('effective_to')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'published', 'superseded', 'rejected'])->default('draft');
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('copied_from_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['price_list_id', 'version_no'], 'plv_list_version_unique');
            $table->index(['price_list_id', 'status', 'effective_from'], 'plv_list_effective_idx');
            $table->foreign('price_list_id', 'plv_list_fk')->references('id')->on('price_lists');
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('price_list_version_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_id');
            $table->decimal('min_qty', 18, 3)->default(1);
            $table->decimal('price', 18, 2);
            $table->timestamps();

            $table->unique(['price_list_version_id', 'product_unit_id', 'min_qty'], 'pli_version_unit_qty_unique');
            $table->index('product_id', 'pli_product_idx');
            $table->foreign('price_list_version_id', 'pli_version_fk')->references('id')->on('price_list_versions')->cascadeOnDelete();
            $table->foreign('product_id', 'pli_product_fk')->references('id')->on('products');
            $table->foreign('product_unit_id', 'pli_unit_fk')->references('id')->on('product_units');
        });
        DB::statement('ALTER TABLE price_list_items ADD CONSTRAINT pli_price_chk CHECK (price >= 0 AND min_qty > 0)');

        // SEC-106: item hanya boleh berubah selama versinya `draft`.
        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $event => $row) {
            $name = 'price_list_items_lock_'.strtolower($event);
            DB::unprepared("
                CREATE TRIGGER {$name} BEFORE {$event} ON price_list_items FOR EACH ROW
                BEGIN
                    IF (SELECT status FROM price_list_versions WHERE id = {$row}.price_list_version_id) <> 'draft' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'price_list_items locked: version is not draft';
                    END IF;
                END
            ");
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('price_list_id', 'customers_price_list_fk')->references('id')->on('price_lists');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign('customers_price_list_fk');
        });
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS price_list_items_lock_{$event}");
        }
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_list_versions');
        Schema::dropIfExists('price_lists');
    }
};
