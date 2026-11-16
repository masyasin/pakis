<?php

namespace Tests\Support;

use App\Domain\Notification\Otp\OtpChannel;
use RuntimeException;

/** Kanal OTP uji: menyimpan alamat & pesan, tidak mengirim ke mana pun. */
final class RecordingOtpChannel implements OtpChannel
{
    /** @var list<array{address: string, message: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $address, string $message): void
    {
        if ($this->fail) {
            throw new RuntimeException('penyedia uji gagal');
        }
        $this->sent[] = ['address' => $address, 'message' => $message];
    }

    public function lastCode(): ?string
    {
        $last = end($this->sent);

        return $last !== false && preg_match('/\b(\d{4,8})\b/', $last['message'], $m) === 1 ? $m[1] : null;
    }
}
