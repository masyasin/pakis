<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log & log keamanan (04 §2.11, SEC-040, SEC-041).
 *
 * Append-only dijaga di level database: trigger menolak UPDATE selalu dan DELETE kecuali variabel
 * sesi `@audit_purge = 1` (hanya diset command `audit:purge`). Jadi kode yang lupa aturan, query
 * mentah, atau akses langsung ke DB pun tidak bisa mengubah jejak.
 */
return new class extends Migration
{
    private const TABLES = ['audit_logs', 'security_events'];

    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 3);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_label', 60)->nullable()->comment("'system', 'scheduler', 'import#12'");
            $table->string('event', 80)->comment("'employee.updated'");
            $table->string('auditable_type', 80)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('request_id', 26)->nullable();

            $table->index(['auditable_type', 'auditable_id', 'occurred_at'], 'audit_obj_idx');
            $table->index(['user_id', 'occurred_at'], 'audit_user_idx');
            $table->index(['event', 'occurred_at'], 'audit_event_idx');
            $table->index('occurred_at', 'audit_time_idx');
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 3);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('identifier', 150)->nullable()->comment('username/email yang dicoba');
            $table->enum('event', [
                'login_success', 'login_failed', 'lockout', 'logout', 'new_device_login',
                'password_changed', 'password_reset_requested', 'password_reset_completed', 'password_reset_by_admin',
                'mfa_enabled', 'mfa_disabled', 'mfa_failed', 'mfa_reset_by_admin',
                'session_revoked', 'token_revoked', 'role_changed', 'permission_changed',
                'device_registered', 'device_approved', 'device_revoked', 'attendance_mock_detected',
                'sensitive_data_viewed', 'mass_export',
            ]);
            $table->enum('channel', ['web', 'api', 'system']);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();

            $table->index(['user_id', 'occurred_at'], 'sec_user_idx');
            $table->index(['event', 'occurred_at'], 'sec_event_idx');
            $table->index(['ip', 'occurred_at'], 'sec_ip_idx');
        });

        foreach (self::TABLES as $table) {
            DB::unprepared("
                CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'
            ");
            DB::unprepared("
                CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW
                BEGIN
                    IF COALESCE(@audit_purge, 0) <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} delete blocked';
                    END IF;
                END
            ");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            Schema::dropIfExists($table);
        }
    }
};
