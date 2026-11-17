<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Approvables\CustomerPhoneChange;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Otp\CustomerOtpService;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Phone\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Ajukan ganti HP pemilik kios (15 §5 `admin.customers.change-phone`, BR-126, BR-166(4), SEC-105,
 * TA-04). Hanya pemegang `customers.change_phone` (middleware) untuk kios dalam scope (binding).
 *
 * Bukti kepemilikan nomor LAMA:
 * - `otp`: kode WhatsApp ke HP lama dicocokkan lalu dihabiskan di dalam transaksi pengajuan;
 * - `office_call`: catatan telepon kantor ke HP lama. Dicatat pengaju sendiri; orang kedua yang
 *   memeriksa adalah penyetuju (`customers.change_phone_approve`, maker-checker mesin) — PA-57.
 *
 * HP baru dicek BR-166(3) sekarang dan sekali lagi saat disetujui. Satu kios satu ganti HP menunggu.
 */
final class RequestPhoneChange
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
        private readonly CustomerOtpService $otp,
        private readonly PhoneConflicts $phones,
    ) {}

    /**
     * @param  array{new_phone: string, method: string, otp_code?: string|null, office_call_note?: string|null, reason: string}  $data
     */
    public function handle(Customer $customer, array $data, User $actor): CustomerPhoneChange
    {
        $method = VerificationMethod::from($data['method']);
        self::assertChangeable($customer);
        self::assertNewPhone($customer, $data['new_phone']);
        $this->phones->assertUsable($data['new_phone'], $customer->id, 'new_phone');

        $otpRow = null;
        if ($method === VerificationMethod::Otp) {
            CustomerOtpService::assertAvailable();
            $otpRow = $this->otp->verify($customer, VerificationType::PhoneChange, $customer->owner_phone, (string) ($data['otp_code'] ?? ''));
        }

        return DB::transaction(function () use ($customer, $data, $actor, $method, $otpRow) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            self::assertChangeable($locked);
            if ($otpRow !== null) {
                if ($otpRow->phone !== $locked->owner_phone) {
                    throw new ApiError(ErrorCode::OTP_EXPIRED, ['code' => ErrorCode::OTP_EXPIRED->message()]);
                }
                $this->otp->consume($otpRow);
            }

            $change = new CustomerPhoneChange;
            $change->forceFill([
                'customer_id' => $locked->id,
                'method' => $method,
                'phone_target' => $data['new_phone'],
                'phone_previous' => $locked->owner_phone,
                'otp_verified_at' => $otpRow !== null ? now() : null,
                'office_call_note' => $method === VerificationMethod::OfficeCall ? mb_substr(trim((string) ($data['office_call_note'] ?? '')), 0, 500) : null,
                'status' => VerificationStatus::Pending,
                'submitted_by' => $actor->id,
            ])->save();
            $change->setRelation('customer', $locked);
            $change->changeReason = $data['reason'];

            // HP lengkap tidak perlu di audit pengajuan: nilai lama/baru dicatat saat diterapkan.
            $this->audit->record('customer.phone_change_requested', $locked, null, [
                'verification_id' => $change->id,
                'method' => $method->value,
                'phone_previous' => PhoneNormalizer::mask($locked->owner_phone),
                'phone_target' => PhoneNormalizer::mask($data['new_phone']),
                'reason' => $data['reason'],
            ]);

            $request = $this->engine->request($change, $actor);
            if ($request !== null) {
                $change->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $change;
        });
    }

    /** Kios boleh diajukan ganti HP: belum digabung, HP-nya sudah terkunci, tanpa ganti HP menunggu. */
    public static function assertChangeable(Customer $customer): void
    {
        if ($customer->status === CustomerStatus::Merged) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'Kios yang sudah digabung tidak bisa diganti HP-nya.');
        }
        if (UpdateCustomer::phoneEditable($customer)) {
            throw new ApiError(ErrorCode::STATE_CONFLICT,
                publicMessage: 'HP kios prospek yang belum terverifikasi cukup diubah lewat form Ubah kios.');
        }
        if (self::pending($customer->id) !== null) {
            throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING, publicMessage: 'Kios ini sudah punya ganti HP yang menunggu persetujuan.');
        }
    }

    public static function pending(int $customerId): ?CustomerPhoneChange
    {
        return CustomerPhoneChange::query()->where('customer_id', $customerId)
            ->where('status', VerificationStatus::Pending)->orderByDesc('id')->first();
    }

    private static function assertNewPhone(Customer $customer, string $phone): void
    {
        if ($phone === $customer->owner_phone) {
            $message = 'HP baru sama dengan HP pemilik sekarang.';
            throw new ApiError(ErrorCode::VALIDATION_FAILED, ['new_phone' => $message], $message);
        }
    }
}
