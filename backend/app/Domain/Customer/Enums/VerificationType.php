<?php

namespace App\Domain\Customer\Enums;

/**
 * Jenis verifikasi kios (`customer_verifications.type`, juga `customer_otp_codes.purpose`, 15 §4.6).
 * Nilai identik dengan ENUM DB.
 */
enum VerificationType: string
{
    case Activation = 'activation';
    case PhoneChange = 'phone_change';

    public function label(): string
    {
        return match ($this) {
            self::Activation => 'Aktivasi',
            self::PhoneChange => 'Ganti HP',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(fn (self $c) => ['v' => $c->value, 'l' => $c->label()], self::cases()), 'l', 'v');
    }
}
