<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Mutasi belum diterima (BR-161, 15 §9.1 `transfer_not_received`) → penerima di lokasi tujuan.
 * Sekali per mutasi per hari setelah `inventory.transfer_receive_reminder_hours`. Tanpa nilai
 * rupiah agar teks yang sama aman terkirim ke Telegram/WhatsApp.
 */
class TransferNotReceivedNotification extends PakisNotification
{
    public function __construct(
        private readonly int $transferId,
        private readonly string $number,
        private readonly string $fromName,
        private readonly string $toName,
        private readonly int $hours,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::TransferNotReceived;
    }

    public function title(): string
    {
        return "Mutasi {$this->number} belum diterima";
    }

    public function body(): string
    {
        return "Kiriman dari {$this->fromName} ke {$this->toName} sudah {$this->hours} jam di perjalanan. "
            .'Periksa barangnya lalu catat penerimaan di halaman mutasi.';
    }

    public function url(): ?string
    {
        return '/mutasi/'.$this->transferId;
    }
}
