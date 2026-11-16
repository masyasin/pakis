<?php

namespace App\Domain\Attendance\Notifications;

use App\Domain\Attendance\Enums\FieldTripStatus;
use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/** Pengajuan tugas luar kota diputuskan (T-036A bagian 2) → pengaju. */
class FieldTripDecided extends PakisNotification
{
    public function __construct(
        private readonly string $number,
        private readonly string $period,
        private readonly FieldTripStatus $status,
        private readonly ?string $note,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::FieldTripDecided;
    }

    public function title(): string
    {
        return $this->status === FieldTripStatus::Approved ? 'Tugas luar kota disetujui' : 'Tugas luar kota ditolak';
    }

    public function body(): string
    {
        $text = "Tugas {$this->number} ({$this->period}) ".($this->status === FieldTripStatus::Approved ? 'disetujui.' : 'ditolak.');

        return $this->note === null || $this->note === '' ? $text : "{$text} Catatan: {$this->note}";
    }
}
