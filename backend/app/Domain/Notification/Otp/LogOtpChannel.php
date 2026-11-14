<?php

namespace App\Domain\Notification\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Driver `log` (lokal/staging): pesan OTP ditulis ke log channel `otp` agar bisa diuji tanpa
 * penyedia sungguhan. Ditolak di produksi oleh OtpManager::assertSafeForEnvironment().
 */
final class LogOtpChannel implements OtpChannel
{
    public function __construct(private readonly string $channel) {}

    public function send(string $address, string $message): void
    {
        Log::channel('otp')->info("OTP {$this->channel}", ['to' => $address, 'message' => $message]);
    }
}
