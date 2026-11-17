<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Access\DataScope;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Notifications\TransferNotReceivedNotification;
use App\Models\User;
use App\Support\Time\DisplayTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Pengingat mutasi belum diterima (BR-161, 15 §11.1 `TransferReceiveReminders`, tiap jam): mutasi
 * `shipped` yang dikirim lebih dari `inventory.transfer_receive_reminder_hours` jam lalu →
 * notifikasi `transfer_not_received` ke pemegang `stock_transfers.receive` yang aktif dan dimensi
 * Stok-nya mencakup lokasi TUJUAN (15 §9.1 "penerima di lokasi tujuan").
 *
 * Sekali per mutasi per hari lokal: `reminder_logs` (UNIQUE subjek+kunci, kunci = tanggal lokal)
 * dicatat dulu baru dikirim, jadi job yang berjalan ganda tidak mengirim dua kali (pola T-071/T-078).
 */
final class TransferReceiveReminders
{
    public const SUBJECT = 'stock_transfer';

    /** @var Collection<int, User>|null */
    private ?Collection $receivers = null;

    public function __construct(private readonly DataScope $scope) {}

    /** @return array{checked: int, sent: int} */
    public function handle(): array
    {
        $today = DisplayTime::toLocal(now())->toDateString();
        $cutoff = now()->subHours((int) setting('inventory.transfer_receive_reminder_hours'));
        $checked = 0;
        $sent = 0;

        StockTransfer::query()->with(['fromLocation:id,name', 'toLocation:id,name,location_id'])
            ->where('status', StockTransferStatus::Shipped->value)
            ->where('shipped_at', '<=', $cutoff)
            ->chunkById(200, function ($transfers) use ($today, &$checked, &$sent) {
                foreach ($transfers as $transfer) {
                    $checked++;
                    if ($this->remind($transfer, $today)) {
                        $sent++;
                    }
                }
            });

        return ['checked' => $checked, 'sent' => $sent];
    }

    private function remind(StockTransfer $transfer, string $today): bool
    {
        // Catat dulu, kirim kemudian: insertOrIgnore 0 baris = sudah diingatkan hari ini.
        $inserted = DB::table('reminder_logs')->insertOrIgnore([
            'subject_type' => self::SUBJECT, 'subject_id' => $transfer->id, 'reminder_key' => $today, 'sent_at' => now(),
        ]);
        if ($inserted === 0) {
            return false;
        }

        $this->receivers ??= User::permission('stock_transfers.receive')->where('is_active', true)->orderBy('id')->get();
        $recipients = $this->receivers
            ->filter(fn (User $user) => $user->id !== $transfer->shipped_by
                && $this->scope->allowsStockLocation($user, $transfer->toLocation->location_id))
            ->values();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TransferNotReceivedNotification(
                $transfer->id,
                $transfer->number,
                $transfer->fromLocation->name,
                $transfer->toLocation->name,
                (int) $transfer->shipped_at?->diffInHours(now()),
            ));
        }

        return true;
    }
}
