<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Enums\DeliveryChannel;
use App\Domain\Notification\Enums\DeliveryStatus;
use App\Domain\Notification\Models\NotificationDelivery;
use App\Domain\Notification\Notifications\ChannelAlert;
use App\Domain\Notification\Notifications\PakisNotification;
use App\Domain\Notification\Telegram\TelegramBot;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Satu upaya kirim ke satu kanal luar dengan pengaman BR-070: kanal yang sedang dijeda dilewati,
 * kuota WhatsApp (harian & per penerima per jam) dicek sebelum kirim, kegagalan beruntun menjeda
 * kanal, dan setiap upaya dicatat di `notification_deliveries` (tanpa isi pesan/alamat).
 *
 * Penghitung memakai Cache::add + increment (atomik, pola jatah OTP) agar job antrean yang berjalan
 * bersamaan tidak melewati kuota. Kuota OTP lupa password memakai kunci lain, jadi tidak terpakai di sini.
 */
class ChannelGuard
{
    public function __construct(
        private readonly TelegramBot $telegram,
        private readonly WhatsAppSender $whatsapp,
    ) {}

    public function deliver(DeliveryChannel $channel, User $user, PakisNotification $notification, ?DeliveryChannel $fallbackFrom = null): DeliveryStatus
    {
        if (Cache::has(self::pausedKey($channel))) {
            return $this->record(DeliveryStatus::SkippedPaused, $channel, $user, $notification, $fallbackFrom);
        }

        if ($channel === DeliveryChannel::WhatsApp && ! $this->reserveWhatsAppQuota($user)) {
            return $this->record(DeliveryStatus::SkippedQuota, $channel, $user, $notification, $fallbackFrom);
        }

        $error = null;
        try {
            $sent = $this->send($channel, $user, $notification);
            $error = $sent ? null : "{$channel->value}_rejected";
        } catch (Throwable $e) {
            $sent = false;
            // Hanya nama kelas: pesan exception bisa memuat alamat atau token penyedia.
            $error = substr(class_basename($e), 0, 60);
            Log::warning("Notifikasi {$channel->value} gagal.", ['error' => $error, 'kind' => $notification->kind()->value]);
        }

        if ($sent) {
            Cache::forget(self::failuresKey($channel));

            return $this->record(DeliveryStatus::Sent, $channel, $user, $notification, $fallbackFrom);
        }

        $this->registerFailure($channel);

        return $this->record(DeliveryStatus::Failed, $channel, $user, $notification, $fallbackFrom, $error);
    }

    public static function pausedKey(DeliveryChannel $channel): string
    {
        return "notify:paused:{$channel->value}";
    }

    private static function failuresKey(DeliveryChannel $channel): string
    {
        return "notify:failures:{$channel->value}";
    }

    private function send(DeliveryChannel $channel, User $user, PakisNotification $notification): bool
    {
        return match ($channel) {
            DeliveryChannel::Email => app(MailChannel::class)->send($user, $notification) !== null,
            DeliveryChannel::Telegram => $this->telegram->sendMessage((string) $user->telegram_chat_id, $notification->toText($user)),
            DeliveryChannel::WhatsApp => $this->whatsapp->send((string) $user->phone, $notification->toText($user)),
        };
    }

    private function reserveWhatsAppQuota(User $user): bool
    {
        $dailyLimit = (int) setting('notification.whatsapp_daily_limit');
        if ($dailyLimit === 0) {
            return false;
        }

        // "Hari" & "jam" menurut zona tampilan, sama dengan cara admin membaca batasnya.
        $now = DisplayTime::toLocal(now());
        $dayKey = 'notify:wa:day:'.$now->format('Ymd');

        if (self::increment($dayKey, 2 * 86400) > $dailyLimit) {
            $this->alertOnce("quota:whatsapp:{$now->format('Ymd')}", 86400,
                "Kuota harian WhatsApp untuk notifikasi ({$dailyLimit}) sudah habis. Notifikasi dialihkan ke Telegram/Email sampai besok.");

            return false;
        }

        $userKey = "notify:wa:user:{$user->id}:".$now->format('YmdH');
        if (self::increment($userKey, 2 * 3600) > (int) setting('notification.whatsapp_hourly_per_user')) {
            // Jatah harian dikembalikan: pesan ini tidak terkirim lewat WhatsApp.
            Cache::decrement($dayKey);

            return false;
        }

        return true;
    }

    private function registerFailure(DeliveryChannel $channel): void
    {
        $minutes = (int) setting('notification.circuit_minutes');

        if (self::increment(self::failuresKey($channel), $minutes * 60) < (int) setting('notification.circuit_failures')) {
            return;
        }

        Cache::put(self::pausedKey($channel), true, $minutes * 60);
        Cache::forget(self::failuresKey($channel));

        $this->alertOnce("paused:{$channel->value}", $minutes * 60,
            "Kanal notifikasi {$channel->label()} gagal berturut-turut dan dijeda {$minutes} menit. Notifikasi dialihkan ke kanal cadangan.");
    }

    /** Peringatan ke log + `monitoring.alert_emails`, sekali per kejadian (kunci & masa berlaku). */
    private function alertOnce(string $key, int $ttlSeconds, string $message): void
    {
        if (! Cache::add("notify:alert:{$key}", true, $ttlSeconds)) {
            return;
        }

        Log::warning($message);

        $emails = array_values(array_filter((array) setting('monitoring.alert_emails')));
        if ($emails !== []) {
            Notification::route('mail', $emails)->notify(new ChannelAlert($message));
        }
    }

    private static function increment(string $key, int $ttlSeconds): int
    {
        Cache::add($key, 0, $ttlSeconds);

        return (int) Cache::increment($key);
    }

    private function record(DeliveryStatus $status, DeliveryChannel $channel, User $user, PakisNotification $notification, ?DeliveryChannel $fallbackFrom, ?string $error = null): DeliveryStatus
    {
        NotificationDelivery::create([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
            'kind' => $notification->kind()->value,
            'channel' => $channel,
            'status' => $status,
            'fallback_from' => $fallbackFrom,
            'error' => $error,
        ]);

        return $status;
    }
}
