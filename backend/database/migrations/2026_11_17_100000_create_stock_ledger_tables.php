<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buku stok (15 §4.8, T-077, ADR-103, BR-101/154/155/159, SEC-102). DDL mengikuti dokumen apa adanya.
 *
 * - `stock_batches`: saldo per lokasi stok + produk + batch + rak. `bin_key` (generated) membuat
 *   UNIQUE berlaku juga untuk batch tanpa rak (NULL tidak pernah bentrok di UNIQUE biasa), sehingga
 *   dua penerimaan bersamaan tidak bisa membuat batch kembar. CHECK `qty_*` = lapis kedua BR-101.
 * - `stock_movements` append-only lewat trigger (pola `audit_logs` 04 §2.11, TANPA jalur purge):
 *   koreksi selalu mutasi lawan, bukan ubah/hapus.
 * - Satu-satunya penulis tabel ini adalah `App\Domain\Inventory\Ledger` (tests/Arch/StockLedgerArchTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_location_id');
            $table->unsignedBigInteger('stock_bin_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('batch_no', 60)->comment("'-' bila produk tidak melacak batch");
            $table->date('expiry_date')->nullable();
            $table->date('received_date')->nullable()->comment('tanggal masuk pertama (tie-break FEFO)');
            $table->decimal('receipt_unit_cost', 18, 4)->nullable()->comment('biaya beli per satuan dasar (telusur, bukan HPP)');
            $table->decimal('qty_on_hand', 18, 3)->default(0);
            $table->decimal('qty_reserved', 18, 3)->default(0);
            $table->enum('status', ['available', 'quarantine', 'expired', 'disposed'])->default('available');
            $table->unsignedBigInteger('quarantine_reason_id')->nullable();
            $table->unsignedBigInteger('bin_key')->storedAs('COALESCE(stock_bin_id, 0)');
            $table->timestamps();

            $table->unique(['stock_location_id', 'product_id', 'batch_no', 'bin_key'], 'sbatch_unique');
            $table->index(['product_id', 'stock_location_id', 'status', 'expiry_date'], 'sbatch_fefo_idx');
            $table->index(['status', 'expiry_date'], 'sbatch_expiry_idx');
            $table->index('stock_bin_id', 'sbatch_bin_idx');
            $table->foreign('stock_location_id', 'sbatch_sl_fk')->references('id')->on('stock_locations');
            $table->foreign('stock_bin_id', 'sbatch_bin_fk')->references('id')->on('stock_bins');
            $table->foreign('product_id', 'sbatch_product_fk')->references('id')->on('products');
        });
        DB::statement('ALTER TABLE stock_batches ADD CONSTRAINT sbatch_qty_chk CHECK (qty_on_hand >= 0 AND qty_reserved >= 0 AND qty_reserved <= qty_on_hand)');

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_batch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_location_id');
            $table->enum('movement_type', [
                'opening_balance', 'receipt', 'purchase_return', 'transfer_out', 'transfer_in',
                'adjustment_in', 'adjustment_out', 'disposal', 'bin_move_out', 'bin_move_in',
                'sale', 'sales_return', 'pos_sale', 'pos_return', 'canvas_load', 'canvas_unload', 'promo_issue',
            ]);
            $table->decimal('qty', 18, 3)->comment('satuan dasar; + masuk, − keluar');
            $table->decimal('qty_after', 18, 3)->comment('saldo batch setelah mutasi');
            $table->decimal('unit_cost', 18, 4)->comment('HPP rata-rata saat mutasi (penerimaan: biaya beli)');
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->date('business_date');
            $table->dateTime('moved_at', 3);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('note', 255)->nullable();

            $table->index(['stock_batch_id', 'moved_at'], 'smov_batch_time_idx');
            $table->index(['source_type', 'source_id'], 'smov_source_idx');
            $table->index(['product_id', 'stock_location_id', 'business_date'], 'smov_product_loc_date_idx');
            $table->foreign('stock_batch_id', 'smov_batch_fk')->references('id')->on('stock_batches');
        });
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT smov_qty_chk CHECK (qty <> 0)');

        // SEC-102: append-only. Berbeda dari audit_logs, tidak ada jalur purge: retensi buku stok
        // selamanya (kartu stok & backfill jurnal Paket F membaca seluruh riwayat).
        DB::unprepared("
            CREATE TRIGGER stock_movements_no_update BEFORE UPDATE ON stock_movements FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
        ");
        DB::unprepared("
            CREATE TRIGGER stock_movements_no_delete BEFORE DELETE ON stock_movements FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_movements is append-only'
        ");

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_batch_id');
            $table->decimal('qty', 18, 3);
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->enum('status', ['active', 'consumed', 'released'])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'status'], 'sres_source_idx');
            $table->index(['stock_batch_id', 'status'], 'sres_batch_status_idx');
            $table->index(['status', 'expires_at'], 'sres_expiry_idx');
            $table->foreign('stock_batch_id', 'sres_batch_fk')->references('id')->on('stock_batches');
        });
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT sres_qty_chk CHECK (qty > 0)');

        Schema::create('product_costs', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->decimal('avg_cost', 18, 4)->default(0)->comment('per satuan dasar');
            $table->decimal('qty_basis', 18, 3)->default(0)->comment('total stok seluruh lokasi saat terakhir dihitung');
            $table->timestamp('last_receipt_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->foreign('product_id', 'pcost_product_fk')->references('id')->on('products');
        });

        Schema::create('product_stock_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_location_id');
            $table->decimal('min_qty_base', 18, 3)->default(0);
            $table->decimal('max_qty_base', 18, 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['product_id', 'stock_location_id'], 'psl_unique');
            $table->foreign('product_id', 'psl_product_fk')->references('id')->on('products');
            $table->foreign('stock_location_id', 'psl_sl_fk')->references('id')->on('stock_locations');
        });
        DB::statement('ALTER TABLE product_stock_levels ADD CONSTRAINT psl_qty_chk CHECK (min_qty_base >= 0 AND (max_qty_base IS NULL OR max_qty_base >= min_qty_base))');

        Schema::create('stock_batch_status_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_batch_id');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->unsignedBigInteger('reason_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->comment('NULL = job sistem');
            $table->timestamp('created_at')->nullable();

            $table->index(['stock_batch_id', 'created_at'], 'sbsl_batch_idx');
            $table->foreign('stock_batch_id', 'sbsl_batch_fk')->references('id')->on('stock_batches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batch_status_logs');
        Schema::dropIfExists('product_stock_levels');
        Schema::dropIfExists('product_costs');
        Schema::dropIfExists('stock_reservations');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS stock_movements_no_delete');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_batches');
    }
};
