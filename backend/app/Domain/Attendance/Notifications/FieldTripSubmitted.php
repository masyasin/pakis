<?php

namespace App\Domain\Attendance\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Tugas luar kota diajukan dari Pakis Pro (T-036A bagian 2) → penyetuju (pola BR-070: atasan
 * langsung, atau kepala lokasi + HRD). Isi tanpa keperluan bebas (bisa memuat data pribadi).
 */
class FieldTripSubmitted extends PakisNotification
{
    public function __construct(
        private readonly string $number,
        private readonly string $employeeName,
        private readonly string $period,
        private readonly string $destination,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::FieldTripSubmitted;
    }

    public function title(): string
    {
        return 'Tugas luar kota menunggu persetujuan';
    }

    public function body(): string
    {
        return "{$this->employeeName} mengajukan tugas luar kota ke {$this->destination}, {$this->period} ({$this->number}).";
    }

    public function url(): ?string
    {
        return '/tugas-luar-kota';
    }
}
