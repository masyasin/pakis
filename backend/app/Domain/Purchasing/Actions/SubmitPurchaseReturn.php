<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Inventory\Ledger\StockLedger;
use App\Domain\Purchasing\Approvables\PurchaseReturnApproval;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Validation\ValidationException;

/**
 * Ajukan retur draf (`draft → pending_approval`, 15 §4.2.1 `purchase_return`, nominal = nilai HPP).
 * Tanpa aturan + `approval.auto_approve_when_no_rule` → langsung disetujui lewat `onApproved` (reservasi
 * dibuat di transaksi yang sama). StockLedger::transaction karena persetujuan langsung mereservasi stok.
 */
final class SubmitPurchaseReturn
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(PurchaseReturn $return, User $actor): PurchaseReturn
    {
        return StockLedger::transaction(function () use ($return, $actor) {
            $locked = PurchaseReturnApproval::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== PurchaseReturnStatus::Draft) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya retur draf yang bisa diajukan.');
            }
            if (! $locked->lines()->exists()) {
                throw ValidationException::withMessages(['lines' => 'Isi minimal satu baris batch sebelum retur diajukan.']);
            }

            $before = $locked->audited();
            $locked->forceFill(['status' => PurchaseReturnStatus::PendingApproval, 'updated_by' => $actor->id])->save();
            $this->audit->record('purchase_return.submitted', $locked, $before, $locked->audited());

            $request = $this->engine->request($locked, $actor);
            if ($request !== null) {
                $locked->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $locked->refresh();
        });
    }
}
