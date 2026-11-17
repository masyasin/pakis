<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Purchasing\Enums\PurchaseReturnStatus;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Tutup retur setelah prinsipal mengonfirmasi (`shipped → closed`, FR-A26). Catatan konfirmasi wajib
 * dan tercatat di audit (riwayat dokumen); lampiran konfirmasi ditunda karena DDL tidak punya kolom
 * berkas (PA-68). Nota debit/pemotongan hutang = Paket F.
 */
final class ClosePurchaseReturn
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(PurchaseReturn $return, string $confirmation, User $actor): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $confirmation, $actor) {
            $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== PurchaseReturnStatus::Shipped) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value], 'Hanya retur yang sudah dikirim yang bisa ditutup.');
            }
            $before = $locked->audited();
            $locked->forceFill(['status' => PurchaseReturnStatus::Closed, 'closed_at' => now(), 'updated_by' => $actor->id])->save();
            $this->audit->record('purchase_return.closed', $locked, $before, [...$locked->audited(), 'confirmation' => $confirmation]);

            return $locked;
        });
    }
}
