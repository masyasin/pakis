<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifikasi (04 §2.14, T-029, BR-070): tabel database notification Laravel (lonceng, selalu aktif)
 * dan catatan upaya kirim kanal luar (email/Telegram/WhatsApp) untuk memantau kuota & kegagalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->comment("jenis notifikasi, mis. 'export_ready'");
            $table->morphs('notifiable');
            $table->text('data')->comment('JSON: title, body, url');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notif_owner_time_idx');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            // Tidak di-FK: kanal luar bisa dicatat walau baris aplikasi tidak (lagi) ada.
            $table->char('notification_id', 36);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40)->comment("jenis notifikasi, mis. 'account_locked'");
            $table->enum('channel', ['email', 'telegram', 'whatsapp']);
            $table->enum('status', ['sent', 'failed', 'skipped_quota', 'skipped_paused']);
            $table->enum('fallback_from', ['email', 'telegram', 'whatsapp'])->nullable()->comment('dicoba karena kanal ini dilewati/gagal');
            $table->string('error', 60)->nullable()->comment('kode pendek, tanpa token/isi pesan');
            $table->timestamp('created_at');

            $table->index('notification_id', 'notif_deliv_notification_idx');
            $table->index(['channel', 'status', 'created_at'], 'notif_deliv_channel_idx');
            $table->index('created_at', 'notif_deliv_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notifications');
    }
};
