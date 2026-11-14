<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master wilayah (04 §2.7, FR-021). Kode bertitik sebagai kunci: `33`, `33.19`, `33.19.04`,
 * `33.19.04.2001`. Diisi `php artisan regions:import` (OQ-003), bukan seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name', 100);
            $table->index('name', 'provinces_name_idx');
        });

        Schema::create('regencies', function (Blueprint $table) {
            $table->char('code', 5)->primary();
            $table->char('province_code', 2);
            $table->string('name', 100);
            $table->index(['province_code', 'name'], 'regencies_prov_name_idx');
            $table->foreign('province_code', 'regencies_prov_fk')->references('code')->on('provinces');
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->char('code', 8)->primary();
            $table->char('regency_code', 5);
            $table->string('name', 100);
            $table->index(['regency_code', 'name'], 'districts_reg_name_idx');
            $table->foreign('regency_code', 'districts_reg_fk')->references('code')->on('regencies');
        });

        Schema::create('villages', function (Blueprint $table) {
            $table->char('code', 13)->primary();
            $table->char('district_code', 8);
            $table->string('name', 100);
            $table->index(['district_code', 'name'], 'villages_dist_name_idx');
            $table->index('name', 'villages_name_idx');
            $table->foreign('district_code', 'villages_dist_fk')->references('code')->on('districts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('villages');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('regencies');
        Schema::dropIfExists('provinces');
    }
};
