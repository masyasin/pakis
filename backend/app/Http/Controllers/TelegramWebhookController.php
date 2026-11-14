<?php

namespace App\Http\Controllers;

use App\Domain\Notification\Telegram\HandleTelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook bot Telegram (05 §4.1 `telegram.webhook`). Telegram mengirim header rahasia yang
 * didaftarkan saat setWebhook; tanpa kecocokan → 403. Selalu 200 untuk update sah agar Telegram
 * tidak mengulang kiriman.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, HandleTelegramUpdate $handler): JsonResponse
    {
        $secret = (string) config('otp.telegram.webhook_secret');
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if($secret === '' || ! hash_equals($secret, $given), 403);

        $handler->handle((array) $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
