<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerVerification;
use Illuminate\Support\Facades\URL;

/**
 * Tautan berkas privat kios (SEC-108/109, T-074; pola selfie T-038 / berkas izin edar T-071): URL
 * relatif bertanda tangan yang kedaluwarsa singkat. Rutenya (`CustomerFileController`) tetap
 * memeriksa izin `customers.view` + scope kios, jadi tautan yang bocor ke pengguna lain tetap 404/403.
 */
final class CustomerFileUrls
{
    /** Umur tautan berkas (SEC-108: "signed URL 5 menit"), sama dengan selfie & berkas izin edar. */
    public const MINUTES = 5;

    public static function photo(Customer $customer): ?string
    {
        return $customer->photo_path === null ? null
            : URL::temporarySignedRoute('admin.customers.photo', now()->addMinutes(self::MINUTES), ['customer' => $customer->id], false);
    }

    public static function restrictedDocument(Customer $customer): ?string
    {
        return $customer->restricted_buyer_document_path === null ? null
            : URL::temporarySignedRoute('admin.customers.restricted-document', now()->addMinutes(self::MINUTES), ['customer' => $customer->id], false);
    }

    public static function verificationPhoto(CustomerVerification $verification): ?string
    {
        return $verification->photo_path === null ? null
            : URL::temporarySignedRoute('admin.customer-verifications.photo', now()->addMinutes(self::MINUTES), ['verification' => $verification->id], false);
    }
}
