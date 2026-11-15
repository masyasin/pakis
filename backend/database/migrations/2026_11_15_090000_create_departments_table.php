<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Departemen berinduk (04 §2.8, T-022, FR-023). Siklus induk dicegah di aplikasi
 * (DepartmentHierarchy), karena CHECK tidak bisa menelusuri rantai induk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'departments_code_unique');
            $table->index(['is_active', 'name'], 'departments_active_name_idx');
            $table->foreign('parent_id', 'departments_parent_fk')->references('id')->on('departments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
