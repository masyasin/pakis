<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\DuplicateGuard;
use App\Domain\Customer\Support\OfficeCallDrafts;
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
 *
 * T-074: HP baru dicek BR-166(3) + HP ganda; nama/titik/desa yang berubah dicek duplikat (BR-166(2)).
 * HP prospek yang berubah saat verifikasi aktivasi masih menunggu membatalkan permintaan itu
 * (BR-153(10)): bukti OTP/telepon kantor hanya berlaku untuk nomor lama. Draf telepon kantor ikut dibuang.
 */
final class UpdateCustomer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DuplicateGuard $duplicates,
        private readonly ApprovalEngine $approvals,
        private readonly OfficeCallDrafts $drafts,
    ) {}

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

            $override = $locked->isDirty(['owner_phone', 'store_name', 'latitude', 'longitude', 'village_code'])
                ? $this->duplicates->check([...$locked->only(Customer::PROFILE), ...array_intersect_key($data, array_flip(DuplicateGuard::CONFIRM_KEYS))], $locked->id, phoneChanged: $locked->isDirty('owner_phone'))
                : null;
            $phoneChanged = $locked->isDirty('owner_phone');

            $locked->updated_by = $userId;
            $locked->save();

            $this->audit->record('customer.updated', $locked, $before, $locked->audited());
            $this->duplicates->recordOverride($locked, $override, $data);

            if ($phoneChanged) {
                $this->withdrawPendingActivation($locked);
            }

            return $locked;
        });
    }

    private function withdrawPendingActivation(Customer $customer): void
    {
        $this->drafts->discard($customer->id);
        $pending = SubmitVerification::pending($customer->id);
        if ($pending === null) {
            return;
        }
        $this->approvals->withdraw($pending);
        $pending->forceFill(['status' => VerificationStatus::Cancelled])->save();
        $this->audit->record('customer.verification_withdrawn', $customer, null, [
            'verification_id' => $pending->id,
            'reason' => 'owner_phone_changed',
        ]);
    }

    public static function phoneEditable(Customer $customer): bool
    {
        return $customer->status === CustomerStatus::Prospect && $customer->owner_phone_verified_at === null;
    }
}
