<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Enums\CustomerStatus;
use App\Domain\Customer\Models\Customer;
use App\Domain\Employee\Enums\EmployeeStatus;
use App\Domain\Employee\Models\Employee;
use App\Models\User;
use App\Support\Api\ApiError;
use App\Support\Api\ErrorCode;

/**
 * Larangan nomor HP kios (BR-166(2)(3), SEC-105, TA-03):
 * - sama dengan kios lain yang masih dipakai (prospek/aktif/tahan kredit) → `CUSTOMER_PHONE_DUPLICATE`;
 *   kios nonaktif atau sudah digabung tidak menghalangi (nomornya boleh dipakai kios penggantinya);
 * - sama dengan HP pegawai aktif ATAU HP akun pengguna aktif → `PHONE_BELONGS_TO_EMPLOYEE`, agar
 *   sales tidak mendaftarkan kios fiktif dengan nomornya sendiri (termasuk nomor yang hanya ada di akun).
 *
 * Nomor yang dibandingkan sudah dinormalisasi `62…` oleh PhoneNormalizer bersama. Data pegawai/akun
 * lama yang mungkin tersimpan `08…`/`+62…` ikut dicocokkan lewat varian awalannya.
 * Pesan galat tidak pernah menyebut siapa pemilik nomor (data pegawai tidak bocor ke sales).
 */
final class PhoneConflicts
{
    /** Dipanggil Action simpan kios & kirim verifikasi DI DALAM transaksinya. */
    public function assertUsable(string $phone, ?int $exceptCustomerId, string $field = 'owner_phone'): void
    {
        if ($this->belongsToStaff($phone)) {
            throw new ApiError(ErrorCode::PHONE_BELONGS_TO_EMPLOYEE, [$field => ErrorCode::PHONE_BELONGS_TO_EMPLOYEE->message()]);
        }
        if ($this->usedByCustomer($phone, $exceptCustomerId) !== null) {
            throw new ApiError(ErrorCode::CUSTOMER_PHONE_DUPLICATE, [$field => ErrorCode::CUSTOMER_PHONE_DUPLICATE->message()]);
        }
    }

    public function belongsToStaff(string $phone): bool
    {
        $variants = self::variants($phone);

        return Employee::query()->whereIn('phone', $variants)->where('status', EmployeeStatus::Active)->exists()
            || User::query()->whereIn('phone', $variants)->where('is_active', true)->exists();
    }

    /** Kios lain (belum nonaktif/digabung) yang memakai nomor ini, atau null. */
    public function usedByCustomer(string $phone, ?int $exceptCustomerId): ?Customer
    {
        return Customer::query()
            ->where('owner_phone', $phone)
            ->whereNotIn('status', [CustomerStatus::Inactive, CustomerStatus::Merged])
            ->when($exceptCustomerId !== null, fn ($q) => $q->whereKeyNot($exceptCustomerId))
            ->orderBy('id')
            ->first();
    }

    /**
     * `62812…` → [`62812…`, `0812…`, `+62812…`, `812…`].
     *
     * @return list<string>
     */
    private static function variants(string $phone): array
    {
        if (! str_starts_with($phone, '62')) {
            return [$phone];
        }
        $local = substr($phone, 2);

        return [$phone, '0'.$local, '+'.$phone, $local];
    }
}
