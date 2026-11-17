<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Batch mendekati ED (BR-157, 15 §9.1 `batch_near_expiry`) → kepala gudang lokasi stok batch (Owner
 * bila tanpa kepala gudang). Satu notifikasi per batch per ambang `inventory.near_expiry_days`.
 * Tanpa nilai rupiah: teks yang sama bisa terkirim ke Telegram/WhatsApp.
 */
class BatchNearExpiryNotification extends PakisNotification
{
    public function __construct(
        private readonly string $productName,
        private readonly string $batchNo,
        private readonly string $locationName,
        private readonly string $expiryLabel,
        private readonly int $daysLeft,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::BatchNearExpiry;
    }

    public function title(): string
    {
        return "ED {$this->daysLeft} hari lagi: {$this->productName}";
    }

    public function body(): string
    {
        return "Batch {$this->batchNo} di {$this->locationName} kedaluwarsa {$this->expiryLabel}. "
            .'Dahulukan batch ini saat keluar barang (FEFO) atau rencanakan retur ke prinsipal.';
    }
}
