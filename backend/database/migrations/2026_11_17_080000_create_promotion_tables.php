<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promo (15 §4.7, FR-A20, BR-169, T-076): kepala promo + target (produk/kategori/prinsipal) +
 * cakupan (semua/daftar harga/area/kios) + tier (ambang qty/nominal → diskon/bundel/bonus).
 * `budget_used` diisi dokumen jual di Paket B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->enum('type', ['percent_discount', 'amount_discount', 'buy_x_get_y', 'bundle_price', 'tiered_discount']);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_stackable')->default(false);
            $table->json('channels');
            $table->decimal('budget_amount', 18, 2)->nullable();
            $table->decimal('budget_used', 18, 2)->default(0);
            $table->enum('funded_by', ['company', 'principal', 'shared'])->default('company');
            $table->unsignedBigInteger('principal_id')->nullable();
            $table->decimal('principal_share_pct', 7, 4)->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'active', 'ended', 'cancelled'])->default('draft');
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('code', 'promotions_code_unique');
            $table->index(['status', 'starts_at', 'ends_at'], 'promotions_status_period_idx');
            $table->foreign('principal_id', 'promo_principal_fk')->references('id')->on('principals');
        });
        DB::statement('ALTER TABLE promotions ADD CONSTRAINT promo_period_chk CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promo_funding_chk CHECK (funded_by = 'company' OR principal_id IS NOT NULL)");
        // Dana bersama wajib menyebut porsi prinsipal (kriteria T-076).
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promo_share_chk CHECK (funded_by <> 'shared' OR (principal_share_pct > 0 AND principal_share_pct < 100))");

        Schema::create('promotion_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->enum('target_type', ['product', 'category', 'principal']);
            $table->unsignedBigInteger('target_id');

            $table->unique(['promotion_id', 'target_type', 'target_id'], 'pt_unique');
            $table->foreign('promotion_id', 'pt_promo_fk')->references('id')->on('promotions')->cascadeOnDelete();
        });

        Schema::create('promotion_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->enum('scope_type', ['all', 'price_list', 'sales_area', 'customer']);
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->unique(['promotion_id', 'scope_type', 'scope_id'], 'ps_unique');
            $table->foreign('promotion_id', 'ps_promo_fk')->references('id')->on('promotions')->cascadeOnDelete();
        });

        Schema::create('promotion_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('promotion_id');
            $table->decimal('min_qty_base', 18, 3)->nullable();
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('discount_pct', 7, 4)->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('bundle_price', 18, 2)->nullable();
            $table->unsignedBigInteger('free_product_id')->nullable();
            $table->decimal('free_qty_base', 18, 3)->nullable();

            $table->index('promotion_id', 'ptier_promo_idx');
            $table->foreign('promotion_id', 'ptier_promo_fk')->references('id')->on('promotions')->cascadeOnDelete();
            $table->foreign('free_product_id', 'ptier_free_fk')->references('id')->on('products');
        });
        DB::statement('ALTER TABLE promotion_tiers ADD CONSTRAINT ptier_threshold_chk CHECK (min_qty_base IS NOT NULL OR min_amount IS NOT NULL)');
        DB::statement('ALTER TABLE promotion_tiers ADD CONSTRAINT ptier_pct_chk CHECK (discount_pct IS NULL OR (discount_pct > 0 AND discount_pct < 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_tiers');
        Schema::dropIfExists('promotion_scopes');
        Schema::dropIfExists('promotion_targets');
        Schema::dropIfExists('promotions');
    }
};
