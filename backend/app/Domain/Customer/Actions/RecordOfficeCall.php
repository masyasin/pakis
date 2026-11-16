<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Approval\ApprovalEngine;
use App\Domain\Audit\AuditLogger;
use App\Domain\Customer\Approvables\CustomerActivation;
use App\Domain\Customer\Enums\VerificationMethod;
use App\Domain\Customer\Enums\VerificationStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Support\OfficeCallDrafts;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Catat telepon kantor ke pemilik kios (BR-165b, SEC-105, T-074): bukti kepemilikan nomor saat OTP
 * WhatsApp tidak tersedia. Wajib pengguna SELAIN pengaju (maker-checker); pencatat boleh menjadi
 * penyetuju bila memegang izin langkahnya (keputusan Mas 16 Nov 2026), jadi cukup dua orang.
 *
 * Membentuk baris `customer_verifications` (method `office_call` + catatan, memenuhi `cv_method_chk`)
 * dari draf pengaju, lalu mengajukan approval `customer_activation` atas nama PENGAJU (bukan pencatat):
 * maker-checker mesin approval tetap mengunci pengaju, dan pencatat tidak menjadi "pengaju".
 * Waktu telepon = `created_at` baris; nama pencatat ada di audit & konteks approval (PA-49).
 */
final class RecordOfficeCall
{
    public function __construct(
        private readonly ApprovalEngine $engine,
        private readonly AuditLogger $audit,
        private readonly OfficeCallDrafts $drafts,
        private readonly PhoneConflicts $phones,
    ) {}

    public function handle(Customer $customer, string $note, User $actor): CustomerActivation
    {
        $draft = $this->drafts->get($customer->id);
        if ($draft === null) {
            throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'Belum ada pengajuan verifikasi kios ini yang menunggu telepon kantor.');
        }
        if ($draft['submitted_by'] === $actor->id) {
            $message = 'Telepon kantor harus dicatat oleh pengguna selain pengaju verifikasi.';
            throw new ApiError(ErrorCode::APPROVAL_SELF_DECISION, ['office_call_note' => $message], $message);
        }
        if ($customer->owner_phone !== $draft['phone']) {
            // HP kios berubah setelah draf dikirim: bukti telepon untuk nomor lama tidak berlaku.
            $this->drafts->discard($customer->id);
            throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'HP pemilik kios berubah setelah verifikasi dikirim. Minta sales mengirim ulang verifikasi.');
        }
        $requester = User::query()->find($draft['submitted_by']) ?? throw new ApiError(ErrorCode::STATE_CONFLICT,
            publicMessage: 'Akun pengaju verifikasi sudah tidak ada. Minta sales mengirim ulang verifikasi.');

        $verification = DB::transaction(function () use ($customer, $note, $actor, $draft, $requester) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            SubmitVerification::assertSubmittable($locked);
            if ($locked->owner_phone !== $draft['phone']) {
                throw new ApiError(ErrorCode::STATE_CONFLICT, publicMessage: 'HP pemilik kios berubah setelah verifikasi dikirim.');
            }
            $this->phones->assertUsable($locked->owner_phone, $locked->id);

            $verification = new CustomerActivation;
            $verification->forceFill([
                'customer_id' => $locked->id,
                'method' => VerificationMethod::OfficeCall,
                'phone_target' => $locked->owner_phone,
                'office_call_note' => mb_substr(trim($note), 0, 500),
                'photo_path' => $draft['photo_path'],
                'latitude' => $draft['latitude'],
                'longitude' => $draft['longitude'],
                'requested_credit_limit' => $draft['requested_credit_limit'],
                'status' => VerificationStatus::Pending,
                'submitted_by' => $requester->id,
            ])->save();
            $verification->setRelation('customer', $locked);
            $verification->officeCallerName = $actor->name;

            $this->audit->record('customer.office_call_recorded', $locked, null, [
                'verification_id' => $verification->id,
                'called_by' => $actor->id,
                'submitted_by' => $requester->id,
            ]);

            $request = $this->engine->request($verification, $requester);
            if ($request !== null) {
                $verification->forceFill(['approval_request_id' => $request->id])->save();
            }

            return $verification;
        });

        $this->drafts->forget($customer->id);

        return $verification;
    }
}
