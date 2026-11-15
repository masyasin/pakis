<?php

namespace App\Domain\Attendance\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Koreksi presensi diajukan (BR-070) → penyetuju (atasan langsung), atau kepala lokasi + HRD bila
 * tidak ada atasan aktif. Isi: nama pegawai, tanggal, jenis — tanpa catatan bebas pegawai (bisa
 * memuat data pribadi dan terkirim lewat kanal luar).
 */
class CorrectionSubmitted extends PakisNotification
{
    public function __construct(
        private readonly string $number,
        private readonly string $employeeName,
        private readonly string $workDate,
        private readonly string $typeLabel,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::CorrectionSubmitted;
    }

    public function title(): string
    {
        return 'Koreksi presensi menunggu persetujuan';
    }

    public function body(): string
    {
        return "{$this->employeeName} mengajukan koreksi {$this->typeLabel} untuk {$this->workDate} ({$this->number}).";
    }

    public function url(): ?string
    {
        // Halaman web koreksi dibuat di T-041 (05 §4.10); path sudah ditetapkan kontrak.
        return '/koreksi-absen';
    }
}
