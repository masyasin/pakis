<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal Telegram untuk OTP lupa password (keputusan Mas 15 Nov 2026, 04 §2.2): chat_id diisi saat
 * pengguna menautkan bot. Event keamanan `telegram_linked` ditambahkan ke ENUM `security_events`.
 */
return new class extends Migration
{
    private const EVENTS = [
        'login_success', 'login_failed', 'lockout', 'logout', 'new_device_login',
        'password_changed', 'password_reset_requested', 'password_reset_completed', 'password_reset_by_admin',
        'mfa_enabled', 'mfa_disabled', 'mfa_failed', 'mfa_reset_by_admin',
        'session_revoked', 'token_revoked', 'role_changed', 'permission_changed',
        'device_registered', 'device_approved', 'device_revoked', 'attendance_mock_detected',
        'sensitive_data_viewed', 'mass_export',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->after('last_login_ip');
            $table->timestamp('telegram_linked_at')->nullable()->after('telegram_chat_id');
            $table->unique('telegram_chat_id', 'users_telegram_chat_id_unique');
        });

        self::setEvents([...self::EVENTS, 'telegram_linked']);
    }

    public function down(): void
    {
        DB::table('security_events')->where('event', 'telegram_linked')->delete();
        self::setEvents(self::EVENTS);

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_telegram_chat_id_unique');
            $table->dropColumn(['telegram_chat_id', 'telegram_linked_at']);
        });
    }

    /**
     * @param  list<string>  $events
     */
    private static function setEvents(array $events): void
    {
        $list = implode(',', array_map(fn ($e) => "'{$e}'", $events));
        DB::statement("ALTER TABLE security_events MODIFY event ENUM({$list}) NOT NULL");
    }
};
