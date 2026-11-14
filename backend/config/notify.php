<?php

/*
 * Kanal luar notifikasi (T-029, BR-070). Konfigurasi infrastruktur (driver), bukan setting bisnis:
 * kanal mana yang dipakai diatur admin di Pengaturan → Notifikasi.
 *
 * WhatsApp:
 * - `none`: tidak tersedia (tidak bisa dicentang di Pengaturan).
 * - `log`:  pesan ditulis ke storage/logs/notify-*.log — lokal/staging saja, DITOLAK di produksi.
 * Penyedia WhatsApp Business API sungguhan menyusul setelah OQ-004.
 *
 * Telegram memakai bot yang sama dengan OTP (config/otp.php → telegram.*).
 */

return [
    'whatsapp' => [
        'driver' => env('NOTIFY_WHATSAPP_DRIVER', 'none'),
    ],
];
