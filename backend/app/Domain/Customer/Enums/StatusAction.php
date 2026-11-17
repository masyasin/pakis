<?php

namespace App\Domain\Customer\Enums;

/**
 * Transisi status kios manual oleh `customers.manage` dengan alasan (15 §6.6, T-074). Gabung dan
 * aktivasi TIDAK di sini: keduanya lewat approval.
 */
enum StatusAction: string
{
    case Hold = 'hold';
    case Release = 'release';
    case Deactivate = 'deactivate';
    case Reactivate = 'reactivate';

    public function label(): string
    {
        return match ($this) {
            self::Hold => 'Tahan kredit',
            self::Release => 'Lepas tahan kredit',
            self::Deactivate => 'Nonaktifkan',
            self::Reactivate => 'Aktifkan kembali',
        };
    }

    /** @return list<CustomerStatus> status asal yang sah */
    public function fromStatuses(): array
    {
        return match ($this) {
            self::Hold => [CustomerStatus::Active],
            self::Release => [CustomerStatus::CreditHold],
            self::Deactivate => [CustomerStatus::Prospect, CustomerStatus::Active, CustomerStatus::CreditHold],
            self::Reactivate => [CustomerStatus::Inactive],
        };
    }

    /** Status tujuan. Aktifkan kembali: kios yang belum pernah terverifikasi kembali ke prospek. */
    public function to(bool $verified): CustomerStatus
    {
        return match ($this) {
            self::Hold => CustomerStatus::CreditHold,
            self::Release => CustomerStatus::Active,
            self::Deactivate => CustomerStatus::Inactive,
            self::Reactivate => $verified ? CustomerStatus::Active : CustomerStatus::Prospect,
        };
    }

    /** @return list<self> aksi yang tersedia dari status ini */
    public static function availableFor(CustomerStatus $status): array
    {
        return array_values(array_filter(self::cases(), fn (self $a) => in_array($status, $a->fromStatuses(), true)));
    }
}
