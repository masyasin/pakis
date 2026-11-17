<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Purchasing\Approvables\PurchaseOrderApproval;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ajukan PO draf (15 §6.6, BR-160, BR-153): `submitted` + permintaan approval `purchase_order`
 * (nominal = grand_total). Syarat: ≥ 1 baris & supplier masih aktif. Tanpa aturan dan
 * `approval.auto_approve_when_no_rule` → langsung `approved` lewat onApproved.
 */
final class SubmitPurchaseOrder
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $locked = PurchaseOrderApproval::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya PO draf yang bisa diajukan.');
            }
            if (! $locked->lines()->exists()) {
                throw ValidationException::withMessages(['lines' => 'Isi minimal satu baris produk sebelum PO diajukan.']);
            }
            if (! (bool) Supplier::query()->whereKey($locked->supplier_id)->value('is_active')) {
                throw ValidationException::withMessages(['supplier_id' => 'Supplier sudah nonaktif. PO hanya bisa diajukan ke supplier aktif.']);
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => PurchaseOrderStatus::Submitted, 'submitted_at' => now(), 'updated_by' => $actor->id])->save();
            $this->audit->record('purchase_order.submitted', $locked, $before, $locked->audited());

            $request = $this->engine->request($locked, $actor);
            if ($request !== null) {
                $locked->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $locked->refresh();
        });
    }
}
