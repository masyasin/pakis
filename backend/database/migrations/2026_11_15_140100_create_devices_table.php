<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perangkat Pakis Pro (04 §2.12, T-031, BR-040). Dua kolom generated + unique index menjaga di
 * level DB: satu akun maksimal satu HP `active`, dan satu HP hanya untuk satu akun `active`.
 * `device_uid` = hash SHA-256 dari klien, bukan ANDROID_ID mentah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', indexName: 'devices_user_fk');
            $table->string('device_uid', 100);
            $table->string('model', 100)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->enum('status', ['pending', 'active', 'revoked', 'replaced', 'rejected'])->default('pending');
            $table->string('request_reason', 255)->nullable();
            $table->unsignedBigInteger('token_id')->nullable()->comment('personal_access_tokens.id yang aktif untuk perangkat ini');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'devices_status_idx');
            $table->index(['user_id', 'status'], 'devices_user_idx');
        });

        DB::statement("ALTER TABLE devices ADD COLUMN active_user_key BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status = 'active', user_id, NULL)) STORED AFTER last_seen_at");
        DB::statement("ALTER TABLE devices ADD COLUMN active_uid_key VARCHAR(100) GENERATED ALWAYS AS (IF(status = 'active', device_uid, NULL)) STORED AFTER active_user_key");
        DB::statement('ALTER TABLE devices ADD UNIQUE KEY devices_one_active_per_user (active_user_key)');
        DB::statement('ALTER TABLE devices ADD UNIQUE KEY devices_one_user_per_phone (active_uid_key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
