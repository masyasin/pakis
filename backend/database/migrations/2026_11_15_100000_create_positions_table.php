<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jabatan (04 §2.8, T-023, FR-024). Mode absen default dipakai bila penugasan tidak menimpanya
 * (BR-042). Siklus atasan jabatan dicegah di aplikasi (PositionHierarchy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('parent_position_id')->nullable();
            $table->unsignedTinyInteger('level')->default(5)->comment('1 = tertinggi');
            $table->enum('attendance_mode', ['location', 'any_location', 'anywhere'])->default('location');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'positions_code_unique');
            $table->index(['department_id', 'is_active'], 'positions_dept_active_idx');
            $table->foreign('department_id', 'positions_dept_fk')->references('id')->on('departments');
            $table->foreign('parent_position_id', 'positions_parent_fk')->references('id')->on('positions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
