<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Otp\CustomerOtpService;
use App\Domain\Customer\Support\OfficeCallDrafts;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use App\Support\Upload\SecureUpload;
use App\Support\Upload\UploadTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Kirim verifikasi aktivasi kios (BR-165, 15 §5 `admin.customers.verify`, T-074). Syarat: kios
 * `prospect`, tanpa verifikasi yang masih menunggu, HP lolos BR-166(2)(3), foto toko (SEC-109) dan
 * titik (akurasi divalidasi request terhadap `customer.verify_max_accuracy_m`), lalu bukti nomor:
 *
 * - `otp`: kode WhatsApp dicocokkan (jatah percobaan tercatat walau langkah berikutnya gagal), lalu
 *   dihabiskan DI DALAM transaksi yang membuat baris & permintaan approval `customer_activation`;
 * - `office_call`: foto + titik disimpan sebagai draf menunggu telepon kantor (OfficeCallDrafts);
 *   baris & permintaan approval dibuat RecordOfficeCall oleh pengguna lain (BR-165b).
 *
 * Mengembalikan baris verifikasi, atau null bila menjadi draf telepon kantor.
 */
final class SubmitVerification
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
        private readonly CustomerOtpService $otp,
        private readonly OfficeCallDrafts $drafts,
        private readonly PhoneConflicts $phones,
        private readonly SecureUpload $uploads,
    ) {}

    /**
     * @param  array{method: string, latitude: string, longitude: string, requested_credit_limit: string, otp_code?: string|null}  $data
     */
    public function handle(Customer $customer, array $data, UploadedFile $photo, User $actor): ?CustomerActivation
    {
        $method = VerificationMethod::from($data['method']);
        self::assertSubmittable($customer);
        $this->phones->assertUsable($customer->owner_phone, $customer->id);

        $otpRow = null;
        if ($method === VerificationMethod::Otp) {
            CustomerOtpService::assertAvailable();
            $otpRow = $this->otp->verify($customer, VerificationType::Activation, $customer->owner_phone, (string) ($data['otp_code'] ?? ''));
        }

        $stored = $this->uploads->store($photo, UploadTarget::privatePhoto('customer-verifications', 'photo'));

        if ($method === VerificationMethod::OfficeCall) {
            $this->drafts->put($customer->id, [
                'photo_path' => $stored->path,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'requested_credit_limit' => $data['requested_credit_limit'],
                'submitted_by' => $actor->id,
                'phone' => $customer->owner_phone,
                'submitted_at' => now()->toIso8601String(),
            ]);
            $this->audit->record('customer.verification_submitted', $customer, null, [
                'method' => $method->value,
                'awaiting_office_call' => true,
                'requested_credit_limit' => $data['requested_credit_limit'],
            ]);

            return null;
        }

        try {
            return DB::transaction(function () use ($customer, $data, $actor, $stored, $otpRow) {
                $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                self::assertSubmittable($locked);
                if ($locked->owner_phone !== $otpRow->phone) {
                    throw new ApiError(ErrorCode::OTP_EXPIRED, ['code' => ErrorCode::OTP_EXPIRED->message()]);
                }
                $this->otp->consume($otpRow);

                $verification = new CustomerActivation;
                $verification->forceFill([
                    'customer_id' => $locked->id,
                    'method' => VerificationMethod::Otp,
                    'phone_target' => $locked->owner_phone,
                    'otp_verified_at' => now(),
                    'photo_path' => $stored->path,
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'requested_credit_limit' => $data['requested_credit_limit'],
                    'status' => VerificationStatus::Pending,
                    'submitted_by' => $actor->id,
                ])->save();
                $verification->setRelation('customer', $locked);

                $this->audit->record('customer.verification_submitted', $locked, null, [
                    'verification_id' => $verification->id,
                    'method' => VerificationMethod::Otp->value,
                    'requested_credit_limit' => $data['requested_credit_limit'],
                ]);

                $request = $this->engine->request($verification, $actor);
                if ($request !== null) {
                    $verification->forceFill(['approval_request_id' => $request->id])->save();
                }
                // Pengajuan OTP menggantikan draf telepon kantor yang mungkin masih menunggu.
                $this->drafts->discard($locked->id);

                return $verification;
            });
        } catch (Throwable $e) {
            Storage::disk($stored->disk)->delete($stored->path);

            throw $e;
        }
    }

    /**
     * Kios masih prospek dan belum punya verifikasi aktivasi yang menunggu keputusan.
     */
    public static function assertSubmittable(Customer $customer): void
    {
        if ($customer->status !== CustomerStatus::Prospect) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, ['status' => $customer->status->value],
                'Hanya kios berstatus Prospek yang bisa diajukan verifikasi aktivasi.');
        }
        if (self::pending($customer->id) !== null) {
            throw new ApiError(ErrorCode::APPROVAL_ALREADY_PENDING, publicMessage: 'Kios ini sudah punya verifikasi yang menunggu persetujuan.');
        }
    }

    public static function pending(int $customerId): ?CustomerActivation
    {
        return CustomerActivation::query()->where('customer_id', $customerId)
            ->where('status', VerificationStatus::Pending)->orderByDesc('id')->first();
    }
}
