<?php

namespace App\Domain\Attendance\Notifications;

use App\Domain\Attendance\Enums\CorrectionStatus;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Koreksi diputuskan (BR-070) → pengaju; presensi manual → pegawai yang bersangkutan. Dibaca di
 * Pakis Pro (lonceng) dan email bila diatur (`notification.on_correction_decided`).
 */
class CorrectionDecided extends PakisNotification
{
    public function __construct(
        private readonly string $number,
        private readonly string $workDate,
        private readonly CorrectionStatus $status,
        private readonly ?string $note,
        private readonly bool $manual = false,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::CorrectionDecided;
    }

    public function title(): string
    {
        if ($this->manual) {
            return 'Presensi Anda diisi manual';
        }

        return $this->status === CorrectionStatus::Approved ? 'Koreksi presensi disetujui' : 'Koreksi presensi ditolak';
    }

    public function body(): string
    {
        $text = $this->manual
            ? "Presensi tanggal {$this->workDate} diisi oleh admin/atasan ({$this->number})."
            : "Koreksi {$this->number} untuk {$this->workDate} ".($this->status === CorrectionStatus::Approved ? 'disetujui.' : 'ditolak.');

        return $this->note === null || $this->note === '' ? $text : "{$text} Catatan: {$this->note}";
    }
}
