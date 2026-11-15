<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hari libur (04 §2.13, T-034, FR-033, BR-062). Satu baris = satu libur dengan RENTANG tanggal
 * (mis. Idul Fitri + cuti bersama beberapa hari; keputusan Mas 15 Nov 2026), bukan satu baris per
 * hari, agar diubah/dihapus sekali. Tanggal = tanggal lokal kantor (seperti `work_date`), bukan
 * momen UTC. Libur khusus lokasi disimpan di `holiday_locations`; `applies_to_all = 1` berarti
 * pivot kosong. Tanpa soft delete (04): menghapus libur ikut menghapus pivotnya (CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->boolean('is_national')->default(true);
            $table->boolean('applies_to_all')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['start_date', 'name'], 'holidays_start_name_unique');
            // Pencarian "libur pada tanggal X" = start_date ≤ X ≤ end_date (resolver T-035).
            $table->index(['start_date', 'end_date'], 'holidays_range_idx');
        });

        DB::statement('ALTER TABLE holidays ADD CONSTRAINT holidays_range_chk CHECK (end_date >= start_date)');

        Schema::create('holiday_locations', function (Blueprint $table) {
            $table->unsignedBigInteger('holiday_id');
            $table->unsignedBigInteger('location_id');

            $table->primary(['holiday_id', 'location_id']);
            $table->foreign('holiday_id', 'hl_holiday_fk')->references('id')->on('holidays')->cascadeOnDelete();
            // Tanpa CASCADE: lokasi yang dipakai libur tidak boleh hilang diam-diam (BR-041, LocationUsage).
            $table->foreign('location_id', 'hl_location_fk')->references('id')->on('locations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_locations');
        Schema::dropIfExists('holidays');
    }
};
