<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockBatchStatus;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Notifications\BatchNearExpiryNotification;
use App\Domain\Inventory\Support\StockAlertRecipients;
use App\Support\Time\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alert mendekati ED (BR-157, 15 §11.1 `NearExpiryAlerts`, 06.00 lokal): per ambang
 * `inventory.near_expiry_days` (default 90/60/30) satu notifikasi per batch, hanya batch
 * `available` yang masih bersaldo.
 *
 * Idempoten lewat `reminder_logs` (UNIQUE subjek+ambang, kunci `ed<hari>`), pola sama dengan
 * pengingat izin edar T-071: ambang dihitung "sisa hari ≤ ambang", jadi job yang terlewat tetap
 * mengirim — tetapi hanya SATU notifikasi untuk ambang terdekat; ambang yang lebih jauh ikut
 * dicatat agar tidak menyusul belakangan.
 */
final class NearExpiryAlerts
{
    public const SUBJECT = 'stock_batch';

    public function __construct(private readonly StockAlertRecipients $recipients) {}

    /** @return array{checked: int, sent: int} */
    public function handle(?string $today = null): array
    {
        $today ??= DisplayTime::toLocal(now())->toDateString();
        $thresholds = self::thresholds();
        if ($thresholds === []) {
            return ['checked' => 0, 'sent' => 0];
        }
        $start = CarbonImmutable::parse($today);
        $until = $start->addDays($thresholds[0])->toDateString();
        $checked = 0;
        $sent = 0;

        StockBatch::query()->with(['product:id,name', 'stockLocation:id,code,name,location_id'])
            ->where('status', StockBatchStatus::Available->value)
            ->where('qty_on_hand', '>', 0)
            // ED hari ini/lewat ditangani ExpireBatches (00.15), bukan alert "mendekati".
            ->where('expiry_date', '>', $today)->where('expiry_date', '<=', $until)
            ->chunkById(500, function ($batches) use ($start, $thresholds, &$checked, &$sent) {
                foreach ($batches as $batch) {
                    $checked++;
                    if ($this->remind($batch, $start, $thresholds)) {
                        $sent++;
                    }
                }
            });

        return ['checked' => $checked, 'sent' => $sent];
    }

    /** @return list<int> ambang menurun dari setting, mis. [90, 60, 30]. */
    public static function thresholds(): array
    {
        $days = array_values(array_unique(array_map('intval', (array) setting('inventory.near_expiry_days'))));
        rsort($days);

        return $days;
    }

    /** @param  list<int>  $thresholds */
    private function remind(StockBatch $batch, CarbonImmutable $today, array $thresholds): bool
    {
        if ($batch->expiry_date === null) {
            return false;
        }
        $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($batch->expiry_date->toDateString()));
        $keys = array_map(fn (int $t) => "ed{$t}", array_values(array_filter($thresholds, fn (int $t) => $daysLeft <= $t)));
        if ($keys === []) {
            return false;
        }

        $logged = DB::table('reminder_logs')->where('subject_type', self::SUBJECT)
            ->where('subject_id', $batch->id)->whereIn('reminder_key', $keys)->pluck('reminder_key')->all();
        $missing = array_values(array_diff($keys, $logged));
        if ($missing === []) {
            return false;
        }

        // Catat dulu, kirim kemudian: insertOrIgnore 0 baris = job lain sudah mengirim.
        $now = now();
        $inserted = DB::table('reminder_logs')->insertOrIgnore(array_map(fn (string $key) => [
            'subject_type' => self::SUBJECT, 'subject_id' => $batch->id, 'reminder_key' => $key, 'sent_at' => $now,
        ], $missing));
        if ($inserted === 0) {
            return false;
        }

        $recipients = $this->recipients->for($batch->stockLocation);
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new BatchNearExpiryNotification(
                $batch->product->name,
                $batch->batch_no,
                $batch->stockLocation->name,
                $batch->expiry_date->translatedFormat('j M Y'),
                $daysLeft,
            ));
        }

        return true;
    }
}
