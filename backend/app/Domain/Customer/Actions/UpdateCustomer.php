<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Ubah profil kios (15 §5 `admin.customers.update`, T-073). Hanya kolom Customer::PROFILE: kode,
 * area/sales (lewat ReassignCustomers), status, limit, verifikasi, foto, dan kelayakan pembeli
 * terbatas tidak pernah diubah di sini (T-074).
 *
 * HP pemilik (BR-126, BR-166(4), PA-47): boleh diubah hanya selama kios `prospect` dan HP belum
 * terverifikasi. Setelah itu wajib jalur ganti HP + approval (T-074) → 409 STATE_CONFLICT.
 */
final class UpdateCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data  sudah divalidasi CustomerRequest */
    public function handle(Customer $customer, array $data, ?int $userId): Customer
    {
        return DB::transaction(function () use ($customer, $data, $userId) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $locked->audited();

            $locked->fill(array_intersect_key($data, array_flip(Customer::PROFILE)));

            if ($locked->isDirty('owner_phone') && ! self::phoneEditable($locked)) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'HP pemilik kios ini sudah terverifikasi atau kiosnya bukan prospek lagi. Ganti HP lewat menu Ganti HP agar diverifikasi ulang.');
            }

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('customer.updated', $locked, $before, $locked->audited());

            return $locked;
        });
    }

    public static function phoneEditable(Customer $customer): bool
    {
        return $customer->status === CustomerStatus::Prospect && $customer->owner_phone_verified_at === null;
    }
}
