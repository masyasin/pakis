<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Ringkasan batch yang ditandai kedaluwarsa oleh job harian 00.15 (BR-157, 15 §9.1 `batch_expired`)
 * → kepala gudang lokasi stok (Owner bila tanpa kepala gudang). Satu notifikasi per lokasi per run.
 */
class BatchExpiredNotification extends PakisNotification
{
    /** @param  list<string>  $samples  "Nama produk (batch)" untuk beberapa batch pertama */
    public function __construct(
        private readonly string $locationName,
        private readonly int $batchCount,
        private readonly array $samples,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::BatchExpired;
    }

    public function title(): string
    {
        return "{$this->batchCount} batch kedaluwarsa di {$this->locationName}";
    }

    public function body(): string
    {
        $more = $this->batchCount > count($this->samples) ? ', dan lainnya' : '';

        return 'Batch berikut kini berstatus kedaluwarsa dan tidak bisa dijual atau dimutasi: '
            .implode(', ', $this->samples)."{$more}. Reservasinya sudah dilepas. Pindahkan ke rak karantina, lalu musnahkan atau retur ke prinsipal.";
    }
}
