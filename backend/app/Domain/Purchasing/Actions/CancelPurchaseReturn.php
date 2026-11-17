<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnLine;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Batalkan retur (PA-68): dari `draft`, `approved` (reservasi aktif dilepas, BR-155), atau `rejected`.
 * Retur yang menunggu persetujuan ditarik dulu lewat permintaan persetujuan (kembali draf). Nomor tetap
 * tercatat.
 */
final class CancelPurchaseReturn
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PurchaseReturn $return, ?string $reason, User $actor): PurchaseReturn
    {
        return StockLedger::transaction(function () use ($return, $reason, $actor) {
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
            if (! $locked->status->isCancellable()) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value],
                    $locked->status === PurchaseReturnStatus::PendingApproval
                        ? 'Retur masih menunggu persetujuan. Tarik pengajuannya dulu.'
                        : 'Retur yang sudah dikirim tidak bisa dibatalkan.');
            }

            $released = 0;
            $reservationIds = PurchaseReturnLine::query()->where('purchase_return_id', $locked->id)->whereNotNull('reservation_id')->pluck('reservation_id');
            foreach (StockReservation::query()->whereIn('id', $reservationIds)->orderBy('id')->get() as $reservation) {
                $released += $this->ledger->release($reservation) ? 1 : 0;
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => PurchaseReturnStatus::Cancelled, 'updated_by' => $actor->id])->save();
            $this->audit->record('purchase_return.cancelled', $locked, $before, [...$locked->audited(), 'reason' => $reason, 'released_reservations' => $released]);

            return $locked;
        });
    }
}
