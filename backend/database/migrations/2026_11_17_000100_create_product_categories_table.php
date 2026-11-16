<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori produk (15 §4.4, FR-A06, BR-151, T-069). Maks 3 tingkat ditegakkan aplikasi
 * (CategoryTree); wajib ED ⇒ wajib batch ditegakkan juga oleh CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->enum('kind', ['insecticide', 'fungicide', 'herbicide', 'molluscicide', 'rodenticide', 'pgr', 'seed', 'fertilizer', 'adjuvant', 'tool', 'other']);
            $table->boolean('requires_batch')->default(true);
            $table->boolean('requires_expiry')->default(true);
            $table->boolean('requires_serial')->default(false);
            $table->boolean('requires_registration')->default(true);
            $table->enum('default_bin_zone', ['general', 'pesticide', 'restricted'])->default('general');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'product_categories_code_unique');
            $table->index(['parent_id', 'sort_order'], 'product_categories_parent_idx');
            $table->foreign('parent_id', 'pc_parent_fk')->references('id')->on('product_categories');
        });

        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT pc_expiry_chk CHECK (requires_expiry = 0 OR requires_batch = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
