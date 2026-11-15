<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tugas luar kota / dinas luar (04 §2.13, T-036A, BR-071). Satu tugas untuk banyak pegawai
 * (sales + driver + kenek); selama rentang tanggalnya lokasi absen yang sah ditambah aturan tugas.
 * Tanggal = tanggal lokal (seperti `work_date`). Tugas tidak dihapus, hanya dibatalkan (riwayat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_trips', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('destination', 150);
            $table->string('purpose', 500)->nullable();
            $table->enum('attendance_rule', ['anywhere', 'destination', 'any_location']);
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled']);
            $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'field_trips_requested_fk');
            $table->foreignId('decided_by')->nullable()->constrained('users', indexName: 'field_trips_decided_fk');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique('number', 'field_trips_number_unique');
            $table->index(['status', 'start_date', 'end_date'], 'field_trips_status_dates_idx');
        });

        DB::statement('ALTER TABLE field_trips ADD CONSTRAINT field_trips_range_chk CHECK (end_date >= start_date)');

        // Titik tujuan aturan `destination` (keputusan Mas 16 Nov 2026: satu tugas bisa beberapa
        // tempat, mis. dua toko mitra di dua kota). Absen sah di titik mana pun pada tanggal tugas.
        Schema::create('field_trip_destinations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('field_trip_id');
            $table->string('name', 150);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('radius_m')->nullable(); // NULL = attendance.default_radius_m
            $table->unsignedTinyInteger('sort_order')->default(0);

            $table->index(['field_trip_id', 'sort_order'], 'ftd_trip_order_idx');
            $table->foreign('field_trip_id', 'ftd_trip_fk')->references('id')->on('field_trips')->cascadeOnDelete();
        });

        Schema::create('field_trip_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('field_trip_id');
            $table->unsignedBigInteger('employee_id');

            $table->primary(['field_trip_id', 'employee_id']);
            $table->index('employee_id', 'fte_employee_idx');
            $table->foreign('field_trip_id', 'fte_trip_fk')->references('id')->on('field_trips')->cascadeOnDelete();
            $table->foreign('employee_id', 'fte_employee_fk')->references('id')->on('employees');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_trip_employees');
        Schema::dropIfExists('field_trip_destinations');
        Schema::dropIfExists('field_trips');
    }
};
