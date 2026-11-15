<?php

namespace App\Domain\Attendance\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Lokasi palsu terdeteksi saat absen (BR-065, BR-070) → HRD + kepala lokasi pegawai. Isi hanya
 * nama, nomor pegawai, dan waktu: TIDAK ada koordinat (notifikasi bisa terkirim lewat email/
 * Telegram/WhatsApp). Rincian (koordinat dibulatkan, perangkat) ada di Log Keamanan.
 */
class AttendanceMockDetected extends PakisNotification
{
    public function __construct(
        private readonly string $employeeName,
        private readonly string $employeeNumber,
        private readonly string $localTime,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::AttendanceMock;
    }

    public function title(): string
    {
        return 'Lokasi palsu terdeteksi';
    }

    public function body(): string
    {
        return "{$this->employeeName} ({$this->employeeNumber}) mencoba absen dengan lokasi palsu pada {$this->localTime}. Absen ditolak dan dicatat di Log Keamanan.";
    }

    public function url(): ?string
    {
        return '/log-keamanan?filter[event][]=attendance_mock_detected';
    }
}
