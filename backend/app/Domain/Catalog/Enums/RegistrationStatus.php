<?php

namespace App\Domain\Catalog\Enums;

/** Status izin edar (`product_registrations.status`, 15 §4.4, BR-152). Nilai identik dengan ENUM DB. */
enum RegistrationStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Berlaku',
            self::Expired => 'Habis',
            self::Revoked => 'Dicabut',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
