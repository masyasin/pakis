<?php

namespace App\Domain\Notification\Channels;

use Illuminate\Support\Facades\Log;

/**
 * Pengirim pesan WhatsApp untuk notifikasi. Penyedia sungguhan (OQ-004) cukup mengganti isi
 * send() sesuai driver; kuota, jeda, dan cadangan sudah ditangani ChannelGuard.
 */
class WhatsAppSender
{
    /** @return bool true bila pesan diterima penyedia */
    public function send(string $phone, string $text): bool
    {
        if (ChannelAvailability::whatsAppDriver() !== 'log') {
            return false;
        }

        // Driver `log` (lokal/staging): nomor disamarkan, isi notifikasi tidak memuat secret (BR-070).
        Log::channel('notify')->info('WhatsApp', ['to' => '***'.substr($phone, -4), 'message' => $text]);

        return true;
    }
}
