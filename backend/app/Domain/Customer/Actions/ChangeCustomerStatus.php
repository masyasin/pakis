<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\StatusAction;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\PendingCustomerApprovals;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Tahan kredit / lepas tahan / nonaktifkan / aktifkan kembali kios (15 §5 `admin.customers.status`,
 * §6.6, T-074) oleh `customers.manage`, alasan wajib, diaudit `customer.status_changed`.
 *
 * - Alasan disimpan di `hold_reason` selama kios tahan kredit atau nonaktif (ditampilkan di detail),
 *   dikosongkan saat kembali aktif; jejak lengkapnya di audit.
 * - Nonaktifkan menarik permintaan approval kios yang menunggu (BR-153(10)).
 * - Aktifkan kembali memeriksa ulang HP (BR-166(2)(3)): selama nonaktif nomornya boleh dipakai kios
 *   lain, jadi bisa saja kini bentrok. Kios yang belum pernah terverifikasi kembali ke prospek.
 * - Penahanan otomatis karena tunggakan = Paket B.
 */
final class ChangeCustomerStatus
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PendingCustomerApprovals $pending,
        private readonly PhoneConflicts $phones,
    ) {}

    public function handle(Customer $customer, StatusAction $action, string $reason, ?int $userId): Customer
    {
        return DB::transaction(function () use ($customer, $action, $reason, $userId) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if (! in_array($locked->status, $action->fromStatuses(), true)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT,
                    publicMessage: "Aksi \"{$action->label()}\" tidak berlaku untuk kios berstatus {$locked->status->label()}.");
            }
            if ($action === StatusAction::Reactivate) {
                $this->phones->assertUsable($locked->owner_phone, $locked->id);
            }

            $before = ['status' => $locked->status->value, 'hold_reason' => $locked->hold_reason];
            $to = $action->to($locked->verified_at !== null);
            $keepsReason = in_array($action, [StatusAction::Hold, StatusAction::Deactivate], true);
            $locked->forceFill([
                'status' => $to,
                'hold_reason' => $keepsReason ? $reason : null,
                'updated_by' => $userId,
            ])->save();

            $withdrawn = $action === StatusAction::Deactivate ? $this->pending->withdrawAll($locked, 'deactivated') : 0;

            $this->audit->record('customer.status_changed', $locked, $before, array_filter([
                'status' => $to->value,
                'hold_reason' => $locked->hold_reason,
                'action' => $action->value,
                'reason' => $reason,
                'approvals_withdrawn' => $withdrawn ?: null,
            ], fn ($v) => $v !== null));

            return $locked;
        });
    }
}
