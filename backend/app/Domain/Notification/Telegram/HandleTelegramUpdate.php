<?php

namespace App\Domain\Notification\Telegram;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Proses satu update bot Telegram (webhook atau `telegram:poll`). Hanya `/start <kode>` dari chat
 * PRIBADI yang ditangani; selain itu diabaikan. Balasan untuk kode salah sengaja generik.
 */
final class HandleTelegramUpdate
{
    public function __construct(
        private readonly TelegramLink $links,
        private readonly TelegramBot $bot,
        private readonly SecurityLogger $security,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $text = is_array($message) ? ($message['text'] ?? null) : null;
        $chatId = is_array($message) ? ($message['chat']['id'] ?? null) : null;

        if (! is_string($text) || ! (is_int($chatId) || is_string($chatId)) || ($message['chat']['type'] ?? null) !== 'private') {
            return;
        }

        if (preg_match('/^\/start(?:\s+(\S+))?$/', trim($text), $m) !== 1) {
            return;
        }

        $userId = isset($m[1]) ? $this->links->consume($m[1]) : null;
        $user = $userId !== null ? User::query()->find($userId) : null;
        $app = (string) setting('general.app_name');

        if ($user === null) {
            $this->bot->sendMessage($chatId, "Kode tidak berlaku. Buka halaman Profil di {$app} lalu tekan \"Hubungkan Telegram\" lagi.");

            return;
        }

        DB::transaction(function () use ($user, $chatId) {
            // Satu chat hanya untuk satu akun: lepaskan dari akun lama bila ada.
            User::query()->where('telegram_chat_id', (string) $chatId)->whereKeyNot($user->id)
                ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);

            $user->forceFill(['telegram_chat_id' => (string) $chatId, 'telegram_linked_at' => now()])->save();

            $this->security->record(SecurityEventType::TelegramLinked, SecurityChannel::System, $user, $user->username);
        });

        $this->bot->sendMessage($chatId, "Akun {$user->username} terhubung ke {$app}. Kode reset password akan dikirim ke chat ini bila Anda memintanya.");
    }
}
