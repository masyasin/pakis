<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox event akuntansi (15 §4.8, ADR-104, T-077).
 *
 * Paket F mem-backfill jurnal dari event lama, jadi isi event tidak boleh berubah setelah ditulis
 * (14 §7 aturan 2). Dijaga trigger di level database seperti audit_logs: UPDATE hanya boleh
 * mengubah kolom milik mesin posting (`status`, `journal_entry_id`, `error`), DELETE selalu
 * ditolak. Koreksi = event baru dengan `revision` naik, bukan mengubah baris lama.
 */
return new class extends Migration
{
    /** Kolom yang tidak boleh berubah setelah insert (semua selain kolom mesin posting). */
    private const IMMUTABLE = [
        'id', 'event_code', 'business_date', 'source_type', 'source_id', 'revision', 'payload', 'created_at',
    ];

    public function up(): void
    {
        Schema::create('accounting_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_code', 60)->comment('14 §7');
            $table->date('business_date');
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->unsignedSmallInteger('revision')->default(1);
            $table->json('payload')->comment('{"lines":[{"role":"inventory","debit":"1250000.00",…}],"refs":{…}}');
            $table->enum('status', ['pending', 'posted', 'skipped', 'failed'])->default('pending');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_code', 'source_type', 'source_id', 'revision'], 'aev_unique');
            $table->index(['status', 'business_date'], 'aev_status_date_idx');
            $table->index(['source_type', 'source_id'], 'aev_source_idx');
        });

        // `<=>` (sama dengan aman-NULL) agar perubahan dari/ke NULL ikut tertangkap.
        $unchanged = implode(' AND ', array_map(fn (string $c) => "NEW.{$c} <=> OLD.{$c}", self::IMMUTABLE));

        DB::unprepared("
            CREATE TRIGGER accounting_events_guard_update BEFORE UPDATE ON accounting_events FOR EACH ROW
            BEGIN
                IF NOT ({$unchanged}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'accounting_events is append-only (only status, journal_entry_id, error may change)';
                END IF;
            END
        ");
        DB::unprepared("
            CREATE TRIGGER accounting_events_no_delete BEFORE DELETE ON accounting_events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'accounting_events delete blocked'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS accounting_events_guard_update');
        DB::unprepared('DROP TRIGGER IF EXISTS accounting_events_no_delete');
        Schema::dropIfExists('accounting_events');
    }
};
