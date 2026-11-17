<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Access\Support\RoleCatalog;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Notifications\StockReconcileMismatchNotification;
use App\Models\User;
use App\Support\Money\Qty;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Rekonsiliasi harian buku stok (BR-154, 15 §11.1 `ReconcileStockLedger`, 01.00 lokal). Hanya
 * membaca; selisih TIDAK dikoreksi otomatis (koreksi = dokumen penyesuaian dengan alasan), tetapi
 * dilaporkan ke Owner & super_admin. Per batch diperiksa:
 *
 * - `sum`: Σ `stock_movements.qty` = `qty_on_hand`;
 * - `last`: `qty_after` mutasi terakhir (id terbesar; mutasi satu batch selalu ditulis di bawah kunci
 *   baris batch, jadi urutan id = urutan saldo) = `qty_on_hand`;
 * - `reserved`: Σ reservasi `active` = `qty_reserved`.
 *
 * Idempoten: notifikasi untuk kumpulan selisih yang sama dikirim sekali per hari (`reminder_logs`).
 */
final class ReconcileStockLedger
{
    public const SUBJECT = 'stock_reconcile';

    /** Batch yang disebut di isi notifikasi (sisanya cukup jumlahnya). */
    private const SAMPLE = 5;

    /**
     * @return array{batches: int, mismatches: list<array{stock_batch_id: int, check: string, expected: string, actual: string}>, notified: bool}
     */
    public function handle(): array
    {
        $mismatches = [];
        $balances = DB::table('stock_batches')->orderBy('id')->get(['id', 'qty_on_hand', 'qty_reserved'])->keyBy('id');

        $sums = DB::table('stock_movements')->groupBy('stock_batch_id')
            ->selectRaw('stock_batch_id, SUM(qty) AS total')->pluck('total', 'stock_batch_id');
        $lasts = DB::table('stock_movements AS m')
            ->joinSub(DB::table('stock_movements')->groupBy('stock_batch_id')->selectRaw('MAX(id) AS id'), 'x', 'x.id', '=', 'm.id')
            ->pluck('m.qty_after', 'm.stock_batch_id');
        $reserved = DB::table('stock_reservations')->where('status', ReservationStatus::Active->value)->groupBy('stock_batch_id')
            ->selectRaw('stock_batch_id, SUM(qty) AS total')->pluck('total', 'stock_batch_id');

        foreach ($balances as $id => $batch) {
            $onHand = Qty::of((string) $batch->qty_on_hand);
            $checks = [
                'sum' => [$onHand, Qty::of((string) ($sums[$id] ?? '0'))],
                'last' => [$onHand, Qty::of((string) ($lasts[$id] ?? '0'))],
                'reserved' => [Qty::of((string) $batch->qty_reserved), Qty::of((string) ($reserved[$id] ?? '0'))],
            ];
            foreach ($checks as $check => [$expected, $actual]) {
                if (! $expected->isEqualTo($actual)) {
                    $mismatches[] = ['stock_batch_id' => (int) $id, 'check' => $check, 'expected' => (string) $expected, 'actual' => (string) $actual];
                }
            }
        }
        // Mutasi yang menunjuk batch tak ada mustahil (FK), jadi tidak perlu diperiksa dari arah sebaliknya.

        return ['batches' => $balances->count(), 'mismatches' => $mismatches, 'notified' => $mismatches !== [] && $this->notify($mismatches)];
    }

    /** @param  non-empty-list<array{stock_batch_id: int, check: string, expected: string, actual: string}>  $mismatches */
    private function notify(array $mismatches): bool
    {
        $batchIds = array_values(array_unique(array_column($mismatches, 'stock_batch_id')));
        $key = DisplayTime::toLocal(now())->toDateString().':'.substr(sha1(json_encode($mismatches, JSON_THROW_ON_ERROR)), 0, 16);

        // Catat dulu, kirim kemudian: 0 baris = proses lain/run sebelumnya hari ini sudah mengirim.
        $inserted = DB::table('reminder_logs')->insertOrIgnore([
            'subject_type' => self::SUBJECT, 'subject_id' => 0, 'reminder_key' => $key, 'sent_at' => now(),
        ]);
        if ($inserted === 0) {
            return false;
        }

        $recipients = User::role([RoleCatalog::OWNER, RoleCatalog::SUPER_ADMIN])->where('is_active', true)->get();
        Notification::send($recipients, new StockReconcileMismatchNotification(count($batchIds), array_slice($batchIds, 0, self::SAMPLE)));

        return true;
    }
}
