<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Kios dari impor cutover yang sudah diverifikasi di sistem lama (T-085, PA-70): langsung `active`
 * tanpa approval `customer_activation`. HANYA dipanggil impor kios setelah syaratnya dicek: kolom
 * `sudah_terverifikasi=ya`, pengimpor memegang `customers.verify`, dan password dikonfirmasi saat
 * proses. Limit kredit tetap 0 & nonaktif (diatur lewat alur limit biasa). Audit
 * `customer.imported_verified` per kios; jumlahnya ada di ringkasan batch impor.
 */
final class ActivateImportedCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Customer $customer, User $actor, int $importBatchId): Customer
    {
        if (! $actor->can('customers.verify')) {
            throw new LogicException('Pengimpor tanpa customers.verify tidak boleh mengaktifkan kios.');
        }

        return DB::transaction(function () use ($customer, $actor, $importBatchId) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($locked->status !== CustomerStatus::Prospect) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $locked->status->value]);
            }
            $locked->forceFill([
                'status' => CustomerStatus::Active,
                'owner_phone_verified_at' => now(),
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record('customer.imported_verified', $locked, ['status' => CustomerStatus::Prospect->value], [
                'status' => CustomerStatus::Active->value,
                'verified_at' => $locked->verified_at?->toIso8601String(),
                'import_batch_id' => $importBatchId,
            ]);

            return $locked;
        });
    }
}
