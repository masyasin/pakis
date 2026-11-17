<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/** Tutup PO manual (BR-160): hanya dari `approved`/`partially_received`, alasan wajib & tercatat. */
final class ClosePurchaseOrder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PurchaseOrder $order, string $reason, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $userId) {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! $locked->status->isOpen()) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya PO yang disetujui atau diterima sebagian yang bisa ditutup.');
            }
            $before = $locked->audited();
            $locked->forceFill([
                'status' => PurchaseOrderStatus::Closed, 'closed_at' => now(), 'close_reason' => trim($reason), 'updated_by' => $userId,
            ])->save();
            $this->audit->record('purchase_order.closed', $locked, $before, $locked->audited());

            return $locked;
        });
    }
}
