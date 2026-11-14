<?php

/*
 * Kanal OTP lupa password (T-015, BR-013). Driver per kanal:
 * - `none`: kanal tidak tersedia (tidak bisa dipilih di Pengaturan, tidak ditawarkan di form).
 * - `log`:  kode ditulis ke storage/logs/otp-*.log — hanya untuk lokal/staging; DITOLAK di produksi.
 * - `bot`:  (Telegram saja) kirim lewat Bot API.
 * Penyedia WhatsApp sungguhan menyusul setelah OQ-004.
 */

return [
    'channels' => [
        'whatsapp' => ['driver' => env('OTP_WHATSAPP_DRIVER', 'none')],
        'telegram' => ['driver' => env('OTP_TELEGRAM_DRIVER', 'none')],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],
];
