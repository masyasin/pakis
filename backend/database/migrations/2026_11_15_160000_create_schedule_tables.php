<?php

use App\Domain\Attendance\Support\ShiftUsage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal kerja (04 §2.13, T-035, BR-061). Pola mingguan berlaku MULAI `effective_from`: pola baru
 * = baris baru, pola lama tetap ada sebagai riwayat (jadwal masa lalu tidak berubah). Pengecualian
 * satu baris per (pegawai, tanggal); `shift_id` NULL = libur. Tanggal = tanggal lokal kantor
 * (seperti `work_date`), bukan momen UTC. FK ke `shifts` tanpa CASCADE: shift yang dipakai jadwal
 * tidak bisa dihapus (ShiftUsage), hanya dinonaktifkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_shift_patterns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('effective_from');
            foreach (ShiftUsage::PATTERN_COLUMNS as $column) {
                // NULL = libur pada hari itu.
                $table->unsignedBigInteger($column)->nullable();
            }
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            // Juga melayani "pola terakhir ≤ tanggal" per pegawai (resolver & daftar).
            $table->unique(['employee_id', 'effective_from'], 'esp_emp_from_unique');
            $table->foreign('employee_id', 'esp_emp_fk')->references('id')->on('employees');
            foreach (ShiftUsage::PATTERN_COLUMNS as $column) {
                $table->foreign($column, 'esp_'.substr($column, 0, 3).'_shift_fk')->references('id')->on('shifts');
            }
        });

        Schema::create('schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->unsignedBigInteger('shift_id')->nullable(); // NULL = libur
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['employee_id', 'date'], 'so_emp_date_unique');
            $table->index('date', 'so_date_idx');
            $table->foreign('employee_id', 'so_emp_fk')->references('id')->on('employees');
            $table->foreign('shift_id', 'so_shift_fk')->references('id')->on('shifts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_overrides');
        Schema::dropIfExists('employee_shift_patterns');
    }
};
