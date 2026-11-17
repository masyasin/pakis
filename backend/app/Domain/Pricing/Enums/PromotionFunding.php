<?php

namespace App\Domain\Pricing\Enums;

/** Sumber dana promo (`promotions.funded_by`). `principal`/`shared` wajib prinsipal; `shared` wajib porsi. */
enum PromotionFunding: string
{
    case Company = 'company';
    case Principal = 'principal';
    case Shared = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Perusahaan',
            self::Principal => 'Prinsipal',
            self::Shared => 'Bersama',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $f) => ['value' => $f->value, 'label' => $f->label()], self::cases());
    }
}
