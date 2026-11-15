<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi presensi (04 §2.13, T-040, BR-069). Jam usulan = TIME lokal pada `work_date` (tanggal
 * lokal); diterapkan ke `attendance_days` saat disetujui, log asli tidak pernah diubah.
 * `pending_key` hanya terisi saat `pending`, sehingga unique-nya = "satu pending per pegawai-tanggal"
 * di tingkat DB (dua pengajuan bersamaan tidak bisa sama-sama lolos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->enum('type', ['check_in', 'check_out', 'both']);
            $table->time('proposed_check_in')->nullable();
            $table->time('proposed_check_out')->nullable();
            $table->unsignedBigInteger('reason_id');
            $table->string('note', 500);
            $table->string('attachment_path', 255)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->unsignedBigInteger('approver_employee_id')->nullable(); // atasan langsung saat diajukan
            $table->unsignedBigInteger('submitted_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->string('pending_key', 30)->nullable()
                ->storedAs("IF(status = 'pending', CONCAT(employee_id, '|', work_date), NULL)");
            $table->timestamps();

            $table->unique('number', 'att_corr_number_unique');
            $table->unique('pending_key', 'att_corr_one_pending');
            $table->index(['status', 'approver_employee_id'], 'att_corr_status_approver_idx');
            $table->index(['employee_id', 'work_date'], 'att_corr_emp_date_idx');
            $table->index(['status', 'created_at'], 'att_corr_status_created_idx');
            $table->foreign('employee_id', 'att_corr_emp_fk')->references('id')->on('employees');
            $table->foreign('reason_id', 'att_corr_reason_fk')->references('id')->on('reference_items');
            $table->foreign('submitted_by', 'att_corr_submitter_fk')->references('id')->on('users');
        });

        DB::statement("ALTER TABLE attendance_corrections ADD CONSTRAINT att_corr_times_chk CHECK (
            (type = 'check_in' AND proposed_check_in IS NOT NULL) OR
            (type = 'check_out' AND proposed_check_out IS NOT NULL) OR
            (type = 'both' AND proposed_check_in IS NOT NULL AND proposed_check_out IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
