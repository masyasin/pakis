<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lokasi kerja (04 §2.8, T-021, BR-041). `head_employee_id` disiapkan TANPA FK: tabel `employees`
 * baru dibuat di T-024, yang juga menambah FK `locations_head_fk` dan pemilihan kepala lokasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->foreignId('location_type_id')->constrained('reference_items', indexName: 'locations_type_fk');
            $table->text('address')->nullable();
            $table->char('village_code', 13)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('attendance_radius_m')->nullable()->comment('NULL = setting attendance.default_radius_m');
            $table->string('phone', 20)->nullable();
            $table->unsignedBigInteger('head_employee_id')->nullable()->comment('FK ke employees ditambah di T-024');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'locations_code_unique');
            $table->index(['is_active', 'name'], 'locations_active_name_idx');
            $table->index('location_type_id', 'locations_type_idx');
            $table->foreign('village_code', 'locations_village_fk')->references('code')->on('villages');
        });

        // Pengaman di DB selain validasi form (04 §2.8): radius 20–5000 m, koordinat wilayah Indonesia
        // dan selalu berpasangan.
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_radius_chk CHECK (attendance_radius_m IS NULL OR attendance_radius_m BETWEEN 20 AND 5000)');
        DB::statement('ALTER TABLE locations ADD CONSTRAINT locations_coord_chk CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude BETWEEN -11 AND 6 AND longitude BETWEEN 94 AND 142))');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
