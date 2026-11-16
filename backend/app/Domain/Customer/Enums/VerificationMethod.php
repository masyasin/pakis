<?php

namespace App\Domain\Customer\Enums;

/**
 * Bukti kepemilikan nomor (BR-165): OTP WhatsApp ke nomor itu, atau telepon kantor oleh pengguna
 * selain pengaju. Nilai identik dengan ENUM `customer_verifications.method`.
 */
enum VerificationMethod: string
{
    case Otp = 'otp';
    case OfficeCall = 'office_call';

    public function label(): string
    {
        return match ($this) {
            self::Otp => 'OTP WhatsApp',
            self::OfficeCall => 'Telepon kantor',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $c) => ['v' => $c->value, 'l' => $c->label()], self::cases()), 'l', 'v');
    }
}
