<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtpCode;
use App\Domain\Customer\Otp\CustomerOtpService;
use App\Domain\Customer\Support\PhoneConflicts;
use App\Models\User;

/**
 * Kirim OTP aktivasi ke HP pemilik kios (15 §5 `admin.customers.otp.send`, BR-165a, SEC-105). Kios
 * harus bisa diajukan verifikasi dan nomornya lolos BR-166(3) lebih dulu, agar jatah kirim tidak
 * terbuang untuk nomor yang pasti ditolak. Ganti HP (OTP ke nomor LAMA) menyusul T-074 bagian 2.
 */
final class SendCustomerOtp
{
    public function __construct(
        private readonly CustomerOtpService $otp,
        private readonly PhoneConflicts $phones,
    ) {}

    public function handle(Customer $customer, User $actor): CustomerOtpCode
    {
        CustomerOtpService::assertAvailable();
        SubmitVerification::assertSubmittable($customer);
        $this->phones->assertUsable($customer->owner_phone, $customer->id);

        return $this->otp->send($customer, VerificationType::Activation, $customer->owner_phone, $actor);
    }
}
