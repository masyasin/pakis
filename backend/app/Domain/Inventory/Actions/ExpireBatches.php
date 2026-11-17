<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Notifications\BatchExpiredNotification;
use App\Domain\Inventory\Support\StockAlertRecipients;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\Notification;

/**
 * Kedaluwarsa otomatis (BR-157, 15 §11.1 `ExpireBatches`, 00.15 lokal): batch `available` dengan
 * `expiry_date ≤ hari ini` → `expired` lewat `StockLedger::setStatus()` (user NULL = job), yang
 * sekaligus melepas reservasi aktif dan mencatat `stock_batch_status_logs`.
 *
 * Satu transaksi per batch (bukan satu transaksi besar): kunci baris tidak ditahan lama saat jam
 * sibuk dan satu batch bermasalah tidak membatalkan yang lain. Idempoten: status diperiksa ulang
 * di bawah kunci, jadi run kedua (atau run bersamaan) tidak menulis log kedua. Notifikasi ringkas
 * per lokasi hanya untuk batch yang benar-benar diubah run ini.
 */
final class ExpireBatches
{
    public const NOTE = 'Kedaluwarsa otomatis (BR-157)';

    /** Batch yang disebut di isi notifikasi (sisanya cukup jumlahnya). */
    private const SAMPLE = 5;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly StockAlertRecipients $recipients,
    ) {}

    /** @return array{expired: int, locations: int} */
    public function handle(?string $today = null): array
    {
        $today ??= DisplayTime::toLocal(now())->toDateString();

        /** @var array<int, list<StockBatch>> $byLocation */
        $byLocation = [];
        $ids = StockBatch::query()->where('status', StockBatchStatus::Available->value)
            ->whereNotNull('expiry_date')->where('expiry_date', '<=', $today)
            ->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $batch = StockLedger::transaction(function () use ($id, $today): ?StockBatch {
                $batch = StockBatch::query()->whereKey($id)->lockForUpdate()->first();
                if ($batch === null || $batch->status !== StockBatchStatus::Available
                    || $batch->expiry_date === null || $batch->expiry_date->toDateString() > $today) {
                    return null;
                }

                return $this->ledger->setStatus($batch, StockBatchStatus::Expired, null, null, self::NOTE);
            });
            if ($batch !== null) {
                $byLocation[$batch->stock_location_id][] = $batch;
            }
        }

        foreach ($byLocation as $locationId => $batches) {
            $this->notify(StockLocation::withTrashed()->findOrFail($locationId), $batches);
        }

        return ['expired' => array_sum(array_map('count', $byLocation)), 'locations' => count($byLocation)];
    }

    /** @param  list<StockBatch>  $batches */
    private function notify(StockLocation $location, array $batches): void
    {
        $recipients = $this->recipients->for($location);
        if ($recipients->isEmpty()) {
            return;
        }

        $samples = array_map(
            fn (StockBatch $b) => "{$b->product->name} ({$b->batch_no})",
            array_slice($batches, 0, self::SAMPLE),
        );
        Notification::send($recipients, new BatchExpiredNotification($location->name, count($batches), $samples));
    }
}
