<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemusnahan & berita acara (15 §4.10, FR-A29, BR-163, T-084) + permintaan lepas karantina
 * (`stock_quarantine_releases`, tabel baru keputusan Mas 17 Nov 2026, PA-69): rak tujuan & qty
 * harus tersimpan selama menunggu approval `stock_quarantine_release` (BR-164).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disposal_reports', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('stock_location_id');
            $table->unsignedBigInteger('reason_id');
            $table->enum('method', ['destroy', 'return_to_principal', 'handover_to_authority']);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'executed', 'rejected', 'cancelled'])->default('draft');
            $table->date('planned_date')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('executed_location', 255)->nullable();
            $table->json('witnesses')->nullable();
            $table->string('document_path', 255)->nullable();
            $table->json('photo_paths')->nullable();
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'bap_number_unique');
            $table->index(['stock_location_id', 'status'], 'bap_sl_status_idx');
            $table->foreign('stock_location_id', 'bap_sl_fk')->references('id')->on('stock_locations');
        });
        // BR-163: berita acara terlaksana wajib punya scan bertanda tangan & waktu pelaksanaan.
        DB::statement("ALTER TABLE disposal_reports ADD CONSTRAINT bap_exec_chk CHECK (status <> 'executed' OR (document_path IS NOT NULL AND executed_at IS NOT NULL))");

        Schema::create('disposal_report_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('disposal_report_id');
            $table->unsignedBigInteger('stock_batch_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('qty_base', 18, 3);
            $table->decimal('unit_cost', 18, 4);

            $table->index('disposal_report_id', 'bapl_bap_idx');
            $table->foreign('disposal_report_id', 'bapl_bap_fk')->references('id')->on('disposal_reports')->cascadeOnDelete();
            $table->foreign('stock_batch_id', 'bapl_batch_fk')->references('id')->on('stock_batches');
        });
        DB::statement('ALTER TABLE disposal_report_lines ADD CONSTRAINT bapl_qty_chk CHECK (qty_base > 0)');

        Schema::create('stock_quarantine_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_batch_id');
            $table->decimal('qty_base', 18, 3);
            $table->unsignedBigInteger('target_bin_id');
            $table->string('reason', 255);
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('released_batch_id')->nullable();
            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['stock_batch_id', 'status'], 'sqr_batch_status_idx');
            $table->foreign('stock_batch_id', 'sqr_batch_fk')->references('id')->on('stock_batches');
            $table->foreign('target_bin_id', 'sqr_bin_fk')->references('id')->on('stock_bins');
        });
        DB::statement('ALTER TABLE stock_quarantine_releases ADD CONSTRAINT sqr_qty_chk CHECK (qty_base > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_quarantine_releases');
        Schema::dropIfExists('disposal_report_lines');
        Schema::dropIfExists('disposal_reports');
    }
};
