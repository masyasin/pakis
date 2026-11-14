<?php

namespace App\Console\Commands;

use App\Domain\Notification\Telegram\HandleTelegramUpdate;
use App\Domain\Notification\Telegram\TelegramBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Ambil update bot Telegram lewat polling — untuk mesin lokal yang tidak bisa menerima webhook.
 * Di server, pakai webhook `/telegram/webhook` (setWebhook dengan secret_token).
 *
 *     php artisan telegram:poll
 */
class PollTelegram extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Proses pesan bot Telegram (penautan akun) lewat polling, untuk lokal';

    public function handle(TelegramBot $bot, HandleTelegramUpdate $handler): int
    {
        if (! $bot->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN / TELEGRAM_BOT_USERNAME belum diisi di .env.');

            return self::FAILURE;
        }

        $offset = (int) Cache::get('telegram:poll-offset', 0);
        $updates = $bot->getUpdates($offset);

        foreach ($updates as $update) {
            $handler->handle($update);
            $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
        }

        Cache::forever('telegram:poll-offset', $offset);
        $this->info(count($updates).' update diproses.');

        return self::SUCCESS;
    }
}
