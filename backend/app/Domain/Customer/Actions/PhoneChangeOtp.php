<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerOtpCode;
use App\Domain\Customer\Otp\CustomerOtpService;
use App\Models\User;

/**
 * OTP ganti HP kios (15 §5 `admin.customers.change-phone.otp.*`, BR-166(4), SEC-105): dikirim ke HP
 * LAMA pemilik lewat WhatsApp saja, tujuan `phone_change`. Throttle & percobaan sama dengan OTP
 * aktivasi (setting `customer.otp_*`). Cek memakai satu jatah tanpa menghabiskan kode; pengajuan
 * ganti HP yang menghabiskannya (pola cek-lalu-pakai PA-50(c)).
 */
final class PhoneChangeOtp
{
    public function __construct(private readonly CustomerOtpService $otp) {}

    public function send(Customer $customer, User $actor): CustomerOtpCode
    {
        CustomerOtpService::assertAvailable();
        RequestPhoneChange::assertChangeable($customer);

        return $this->otp->send($customer, VerificationType::PhoneChange, $customer->owner_phone, $actor);
    }

    public function check(Customer $customer, string $code): void
    {
        CustomerOtpService::assertAvailable();
        $this->otp->verify($customer, VerificationType::PhoneChange, $customer->owner_phone, $code);
    }
}
