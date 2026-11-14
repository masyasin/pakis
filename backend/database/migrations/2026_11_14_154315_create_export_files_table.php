<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berkas ekspor .xlsx per pengguna (04 §2.10, T-006). Berkas fisik di disk privat; baris ini
 * menyimpan status, parameter listing (untuk membangun ulang query di job), dan masa berlaku.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', function (Blueprint $table) {
            $table->id();
            $table->string('listing', 60)->comment("kunci listing, mis. 'attendance_days'");
            $table->json('params')->comment('q/filter/sort yang dipakai');
            $table->enum('status', ['queued', 'processing', 'ready', 'failed', 'expired'])->default('queued');
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['created_by', 'created_at'], 'export_owner_idx');
            $table->index(['status', 'expires_at'], 'export_status_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
