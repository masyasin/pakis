<?php

namespace App\Domain\Notification\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Klien minimal Telegram Bot API (tanpa dependency baru). Token bot ada di URL Bot API, jadi galat
 * TIDAK pernah dicatat apa adanya — hanya status/kode, agar token tidak bocor ke log.
 */
final class TelegramBot
{
    private const BASE = 'https://api.telegram.org/bot';

    private const TIMEOUT_SECONDS = 5;

    public function configured(): bool
    {
        return filled(config('otp.telegram.bot_token')) && filled(config('otp.telegram.bot_username'));
    }

    public function sendMessage(string|int $chatId, string $text): bool
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]) !== null;
    }

    /**
     * Ambil update untuk mode polling (lokal tanpa webhook).
     *
     * @return list<array<string, mixed>>
     */
    public function getUpdates(int $offset): array
    {
        $result = $this->call('getUpdates', ['offset' => $offset, 'timeout' => 0]);

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload): mixed
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post(self::BASE.config('otp.telegram.bot_token').'/'.$method, $payload);
        } catch (Throwable $e) {
            Log::warning("Telegram {$method} gagal: tidak bisa terhubung.", ['error' => class_basename($e)]);

            return null;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning("Telegram {$method} gagal.", ['status' => $response->status(), 'description' => $response->json('description')]);

            return null;
        }

        return $response->json('result');
    }
}
