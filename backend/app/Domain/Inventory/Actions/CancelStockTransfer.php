<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockTransfer;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Batalkan mutasi (15 §6.6). Hanya draf (keputusan Mas, rencana T-082): mutasi yang sudah dikirim
 * barangnya di TRANSIT, salah kirim diselesaikan dengan menerima lalu mutasi balik. Reservasi batch
 * draf dilepas di transaksi yang sama (BR-155).
 */
final class CancelStockTransfer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StockLedger $ledger,
    ) {}

    public function handle(StockTransfer $transfer, int $userId): StockTransfer
    {
        return StockLedger::transaction(function () use ($transfer, $userId) {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->status !== StockTransferStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value],
                    'Mutasi yang sudah dikirim tidak bisa dibatalkan. Terima barangnya, lalu buat mutasi balik.');
            }
            SaveStockTransfer::releaseReservations($this->ledger, $locked);

            $before = $locked->audited();
            $locked->forceFill(['status' => StockTransferStatus::Cancelled, 'updated_by' => $userId])->save();
            $this->audit->record('stock_transfer.cancelled', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
