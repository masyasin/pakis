<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Batalkan PO yang belum berjalan (draf atau ditolak). PO yang menunggu persetujuan ditarik lewat
 * kotak Persetujuan (kembali draf); PO disetujui hanya bisa ditutup (BR-160).
 */
final class CancelPurchaseOrder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PurchaseOrder $order, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $userId) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Rejected], true)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya PO draf atau ditolak yang bisa dibatalkan.');
            }
            $before = $locked->audited();
            $locked->forceFill(['status' => PurchaseOrderStatus::Cancelled, 'updated_by' => $userId])->save();
            $this->audit->record('purchase_order.cancelled', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
