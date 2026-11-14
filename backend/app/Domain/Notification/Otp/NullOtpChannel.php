<?php

namespace App\Domain\Notification\Otp;

/** Driver `none`: kanal tidak tersedia. Tidak pernah dipanggil karena kanal tidak ditawarkan. */
final class NullOtpChannel implements OtpChannel
{
    public function send(string $address, string $message): void
    {
        // Sengaja kosong: OtpManager::available() = false, sehingga kanal ini tidak bisa dipilih.
    }
}
