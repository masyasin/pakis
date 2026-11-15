<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pegawai & penugasan (04 §2.9, T-024). Tabel `assignments` dibuat di sini karena pegawai baru
 * wajib disertai penugasan pertama; logika mutasi lengkap menyusul di T-025.
 *
 * Juga memasang FK yang tertunda karena tabel `employees` belum ada saat itu:
 * `users.employee_id` (T-013) dan `locations.head_employee_id` (T-021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 30)->comment('dari NumberGenerator(employee)');
            $table->string('full_name', 150);
            // NIK tidak pernah plaintext (SEC-010): terenkripsi + HMAC untuk cek unik.
            $table->text('nik_enc')->nullable();
            $table->char('nik_hash', 64)->nullable();
            $table->enum('gender', ['L', 'P']);
            $table->date('birth_date')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();
            $table->char('village_code', 13)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->unsignedBigInteger('employment_status_id');
            $table->date('join_date');
            $table->date('exit_date')->nullable();
            $table->unsignedBigInteger('exit_reason_id')->nullable();
            $table->string('exit_note', 255)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('employee_number', 'employees_number_unique');
            $table->unique('nik_hash', 'employees_nik_hash_unique');
            $table->index(['status', 'full_name'], 'employees_status_name_idx');
            $table->index('employment_status_id', 'employees_emp_status_idx');
            $table->index('join_date', 'employees_join_date_idx');
            $table->foreign('employment_status_id', 'employees_emp_status_fk')->references('id')->on('reference_items');
            $table->foreign('exit_reason_id', 'employees_exit_reason_fk')->references('id')->on('reference_items');
            $table->foreign('village_code', 'employees_village_fk')->references('code')->on('villages');
        });

        DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_exit_chk CHECK (exit_date IS NULL OR exit_date >= join_date)');
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_inactive_chk CHECK (status = 'active' OR exit_date IS NOT NULL)");

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('supervisor_employee_id')->nullable();
            $table->enum('attendance_mode', ['location', 'any_location', 'anywhere'])->nullable()->comment('NULL = ikut jabatan');
            $table->boolean('is_primary')->default(true);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('note', 255)->nullable();
            // BR-051: index unik atas kolom ini = maksimal satu penugasan utama aktif per pegawai
            // (NULL tidak bentrok, jadi penugasan tambahan / yang sudah selesai bebas).
            $table->unsignedBigInteger('active_primary_key')->nullable()
                ->storedAs('IF(is_primary = 1 AND end_date IS NULL, employee_id, NULL)');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('active_primary_key', 'assignments_one_active_primary');
            $table->index(['employee_id', 'start_date'], 'assignments_emp_start_idx');
            $table->index(['location_id', 'end_date'], 'assignments_loc_end_idx');
            $table->index(['supervisor_employee_id', 'end_date'], 'assignments_sup_end_idx');
            $table->index(['position_id', 'end_date'], 'assignments_pos_end_idx');
            $table->foreign('employee_id', 'assignments_emp_fk')->references('id')->on('employees');
            $table->foreign('position_id', 'assignments_pos_fk')->references('id')->on('positions');
            $table->foreign('location_id', 'assignments_loc_fk')->references('id')->on('locations');
            $table->foreign('supervisor_employee_id', 'assignments_sup_fk')->references('id')->on('employees');
        });

        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_dates_chk CHECK (end_date IS NULL OR end_date >= start_date)');
        DB::statement('ALTER TABLE assignments ADD CONSTRAINT assignments_not_self_sup_chk CHECK (supervisor_employee_id IS NULL OR supervisor_employee_id <> employee_id)');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('employee_id', 'users_employee_fk')->references('id')->on('employees');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreign('head_employee_id', 'locations_head_fk')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        Schema::table('locations', fn (Blueprint $table) => $table->dropForeign('locations_head_fk'));
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign('users_employee_fk'));
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('employees');
    }
};
