<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absensi (04 §2.13, T-036). `attendance_logs` = setiap kiriman absen yang DITERIMA (append-only;
 * lokasi palsu tidak pernah masuk, BR-065); `client_uuid` unik untuk idempotensi kiriman ulang
 * dari antrean offline (BR-067). `attendance_days` = ringkasan per pegawai per `work_date`
 * (tanggal lokal), dihitung ulang oleh resolver status (T-037). Waktu DATETIME disimpan UTC.
 * `attendance_days.shift_id`/`location_id` sengaja tanpa FK (04): snapshot historis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->char('client_uuid', 36);
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('device_id');
            $table->enum('type', ['in', 'out']);
            $table->dateTime('recorded_at');
            $table->dateTime('server_received_at');
            $table->dateTime('client_time');
            $table->boolean('is_offline')->default(false);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 7, 2)->nullable();
            $table->boolean('is_mock')->default(false);
            $table->unsignedBigInteger('matched_location_id')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('within_radius');
            $table->string('selfie_path', 255)->nullable();
            $table->timestamp('selfie_purged_at')->nullable();
            $table->string('app_version', 20)->nullable();
            $table->json('flags')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique('client_uuid', 'att_logs_client_uuid_unique');
            $table->index(['employee_id', 'recorded_at'], 'att_logs_emp_time_idx');
            $table->index(['selfie_purged_at', 'recorded_at'], 'att_logs_purge_idx');
            $table->foreign('employee_id', 'att_logs_emp_fk')->references('id')->on('employees');
            $table->foreign('user_id', 'att_logs_user_fk')->references('id')->on('users');
            $table->foreign('device_id', 'att_logs_device_fk')->references('id')->on('devices');
            $table->foreign('matched_location_id', 'att_logs_loc_fk')->references('id')->on('locations');
        });

        Schema::create('attendance_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->dateTime('scheduled_start')->nullable();
            $table->dateTime('scheduled_end')->nullable();
            $table->unsignedBigInteger('check_in_log_id')->nullable();
            $table->unsignedBigInteger('check_out_log_id')->nullable();
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('work_minutes')->default(0);
            $table->enum('status', ['present', 'late', 'early_leave', 'no_checkout', 'absent', 'holiday', 'off']);
            $table->boolean('is_corrected')->default(false);
            $table->boolean('is_offline')->default(false);
            $table->boolean('has_anomaly')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date'], 'att_days_emp_date_unique');
            $table->index(['work_date', 'location_id', 'status'], 'att_days_date_loc_status_idx');
            $table->index(['work_date', 'department_id'], 'att_days_date_dept_idx');
            $table->index(['work_date', 'status'], 'att_days_date_status_idx');
            $table->foreign('employee_id', 'att_days_emp_fk')->references('id')->on('employees');
            $table->foreign('check_in_log_id', 'att_days_in_fk')->references('id')->on('attendance_logs');
            $table->foreign('check_out_log_id', 'att_days_out_fk')->references('id')->on('attendance_logs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('attendance_logs');
    }
};
