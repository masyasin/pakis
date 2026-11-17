<?php

namespace App\Domain\Inventory\Notifications;

use App\Domain\Notification\Enums\NotificationKind;
use App\Domain\Notification\Notifications\PakisNotification;

/**
 * Rekonsiliasi harian menemukan saldo batch ≠ kartu stok (BR-154, 15 §9.1 `stock_reconcile_mismatch`)
 * → Owner & super_admin. Hanya jumlah & nomor batch (tanpa nilai rupiah): teks yang sama bisa
 * terkirim ke Telegram/WhatsApp.
 */
class StockReconcileMismatchNotification extends PakisNotification
{
    /** @param  list<int>  $sampleBatchIds */
    public function __construct(
        private readonly int $batchCount,
        private readonly array $sampleBatchIds,
    ) {}

    public function kind(): NotificationKind
    {
        return NotificationKind::StockReconcileMismatch;
    }

    public function title(): string
    {
        return "Selisih buku stok: {$this->batchCount} batch";
    }

    public function body(): string
    {
        $sample = implode(', ', array_map(fn (int $id) => "#{$id}", $this->sampleBatchIds));
        $more = $this->batchCount > count($this->sampleBatchIds) ? ' dan lainnya' : '';

        return "Rekonsiliasi harian menemukan saldo batch yang tidak cocok dengan kartu stok (batch {$sample}{$more}). "
            .'Saldo tidak dikoreksi otomatis; periksa kartu stok dan buat penyesuaian bila perlu.';
    }
}
