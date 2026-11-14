<?php

namespace App\Domain\Notification\Otp;

/**
 * Pengirim pesan OTP satu kanal (WhatsApp, Telegram). `address` = nomor HP atau chat_id Telegram.
 * Implementasi TIDAK boleh mencatat rahasia (token bot/API key) maupun meneruskan galat yang
 * membawanya.
 */
interface OtpChannel
{
    public function send(string $address, string $message): void;
}
