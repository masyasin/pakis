<?php

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Enums\VerificationType;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Otp\CustomerOtpService;

/**
 * Cek kode OTP aktivasi sebelum kirim verifikasi (15 §5 `admin.customers.otp.check`). Memakai satu
 * jatah percobaan, TIDAK menghabiskan kode: kirim verifikasi mencocokkan ulang lalu menghabiskannya
 * di dalam transaksinya (pola OtpService Fase 0: cek dulu, habiskan saat aksi akhir).
 */
final class CheckCustomerOtp
{
    public function __construct(private readonly CustomerOtpService $otp) {}

    public function handle(Customer $customer, string $code): void
    {
        CustomerOtpService::assertAvailable();
        $this->otp->verify($customer, VerificationType::Activation, $customer->owner_phone, $code);
    }
}
