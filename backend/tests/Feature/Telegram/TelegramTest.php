<?php

use App\Domain\Audit\Models\SecurityEvent;
use App\Domain\Notification\Otp\OtpManager;
use App\Domain\Notification\Telegram\TelegramLink;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Kanal Telegram (keputusan Mas 15 Nov 2026): penautan akun lewat bot & OTP lewat bot.
 */

const BOT_TOKEN = '123456:UJI-token-bot-palsu';
const WEBHOOK_SECRET = 'rahasia-webhook-uji';

beforeEach(function () {
    config([
        'otp.channels.telegram.driver' => 'bot',
        'otp.telegram.bot_token' => BOT_TOKEN,
        'otp.telegram.bot_username' => 'PakisOneBot',
        'otp.telegram.webhook_secret' => WEBHOOK_SECRET,
    ]);
    $this->user = User::factory()->create(['username' => 'siti.aminah']);
});

/** Bot API palsu yang selalu sukses (dipanggil per test agar test galat bisa memasang respons lain). */
function fakeBotOk(): void
{
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
}

function telegramUpdate(string $text, int|string $chatId = 555000111, string $type = 'private', ?string $secret = WEBHOOK_SECRET)
{
    $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

    return test()->withHeaders($headers)->postJson('/telegram/webhook', [
        'update_id' => 1,
        'message' => ['message_id' => 1, 'text' => $text, 'chat' => ['id' => $chatId, 'type' => $type]],
    ]);
}

test('tautan penautan berisi kode sekali pakai ke bot', function () {
    $link = app(TelegramLink::class)->create($this->user);

    expect($link)->toStartWith('https://t.me/PakisOneBot?start=')
        ->and(strlen(substr($link, strlen('https://t.me/PakisOneBot?start='))))->toBeGreaterThanOrEqual(32);
});

test('webhook tanpa atau dengan secret salah → 403', function (?string $secret) {
    fakeBotOk();
    telegramUpdate('/start apa', secret: $secret)->assertForbidden();
})->with([null, 'salah']);

test('/start <kode> menautkan chat ke akun, kode hangus, event telegram_linked, bot membalas', function () {
    fakeBotOk();
    $link = app(TelegramLink::class)->create($this->user);
    $code = substr($link, strrpos($link, '=') + 1);

    telegramUpdate("/start {$code}")->assertOk();

    $this->user->refresh();
    expect($this->user->telegram_chat_id)->toBe('555000111')
        ->and($this->user->telegram_linked_at)->not->toBeNull()
        ->and(SecurityEvent::query()->where('event', 'telegram_linked')->where('user_id', $this->user->id)->exists())->toBeTrue();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') && $r['chat_id'] === 555000111);

    // Kode sekali pakai: chat lain tidak bisa memakainya.
    telegramUpdate("/start {$code}", 999)->assertOk();
    expect($this->user->refresh()->telegram_chat_id)->toBe('555000111');
});

test('kode salah/kedaluwarsa atau chat grup tidak menautkan', function () {
    fakeBotOk();
    $link = app(TelegramLink::class)->create($this->user);
    $code = substr($link, strrpos($link, '=') + 1);

    telegramUpdate('/start kode-salah')->assertOk();
    telegramUpdate("/start {$code}", type: 'group')->assertOk();
    $this->travel(11)->minutes();
    telegramUpdate("/start {$code}")->assertOk();

    expect($this->user->refresh()->telegram_chat_id)->toBeNull();
});

test('chat yang sudah tertaut ke akun lain pindah ke akun baru', function () {
    fakeBotOk();
    $old = User::factory()->create(['telegram_chat_id' => '555000111']);
    $link = app(TelegramLink::class)->create($this->user);

    telegramUpdate('/start '.substr($link, strrpos($link, '=') + 1))->assertOk();

    expect($old->refresh()->telegram_chat_id)->toBeNull()
        ->and($this->user->refresh()->telegram_chat_id)->toBe('555000111');
});

test('driver bot mengirim OTP lewat sendMessage tanpa mencatat token bot', function () {
    fakeBotOk();
    Log::spy();

    app(OtpManager::class)->channel('telegram')->send('555000111', 'Kode 123456');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.telegram.org/bot'.BOT_TOKEN.'/sendMessage'
        && $r['chat_id'] === '555000111' && $r['text'] === 'Kode 123456');
    Log::shouldNotHaveReceived('info');
});

test('kegagalan bot dicatat tanpa token', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
    Log::spy();

    app(OtpManager::class)->channel('telegram')->send('555000111', 'Kode 123456');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), BOT_TOKEN));
});
