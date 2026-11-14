<?php

namespace App\Domain\Notification\Otp;

use App\Domain\Notification\Telegram\TelegramBot;

/** Driver `bot` kanal Telegram: OTP dikirim sebagai pesan bot ke chat yang sudah ditautkan. */
final class TelegramBotOtpChannel implements OtpChannel
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function send(string $address, string $message): void
    {
        // Gagal kirim sudah dicatat TelegramBot (tanpa token); respons lupa password tetap generik.
        $this->bot->sendMessage($address, $message);
    }
}
