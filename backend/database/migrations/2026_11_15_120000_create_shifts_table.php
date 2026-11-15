<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master shift (04 §2.13, T-033, FR-031, BR-060). Jam disimpan sebagai TIME lokal kantor
 * (`general.timezone`), bukan UTC: shift adalah jam dinding, sama untuk setiap tanggal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('late_tolerance_min')->default(0);
            $table->unsignedSmallInteger('checkin_open_min')->default(120)->comment('boleh absen masuk X menit sebelum start');
            $table->unsignedSmallInteger('checkout_close_min')->default(360)->comment('boleh absen pulang s.d. X menit setelah end');
            $table->char('color', 7)->default('#2F6B3B');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('code', 'shifts_code_unique');
            $table->index(['is_active', 'name'], 'shifts_active_idx');
        });

        // BR-060: shift biasa harus berakhir setelah mulai; hanya shift lintas hari yang boleh "terbalik".
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_midnight_chk CHECK (crosses_midnight = 1 OR end_time > start_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
